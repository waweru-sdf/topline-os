<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Plan;
use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Input;
use App\Support\Text;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accounting built from what Topline already records.
 * Shops: profit and loss per month (sales, cost of goods, expenses), payment split, CSV exports.
 * Topline: plan income, commissions, payouts, MRR, CSV exports.
 * Months follow Kenyan time (a sale at 01:00 on the 1st belongs to the new month).
 */
class Accounting
{
    /** Sales that count as takings (not unpaid, not waiting for a payment check, not cancelled). */
    private const NOT_TAKINGS = [Sale::AWAITING_PAYMENT, Sale::AWAITING_CONFIRMATION, Sale::CANCELLED];

    /** @return array{0: Carbon, 1: Carbon, 2: string} UTC start, UTC end (exclusive), 'YYYY-MM' */
    public static function month(?string $month): array
    {
        $tz = config('topline.timezone');
        $month = $month ?: now($tz)->format('Y-m');
        if (! preg_match('/^(20\d\d)-(0[1-9]|1[0-2])$/', $month)) {
            throw ApiError::invalid('Choose a month like 2026-11.', 'month');
        }
        $start = Carbon::createFromFormat('Y-m-d H:i:s', "{$month}-01 00:00:00", $tz);

        return [$start->copy()->utc(), $start->copy()->addMonth()->utc(), $month];
    }

    private static function previousMonths(string $month, int $count): array
    {
        $tz = config('topline.timezone');
        $m = Carbon::createFromFormat('Y-m-d', "{$month}-01", $tz);

        return collect(range($count - 1, 0))->map(fn ($i) => $m->copy()->subMonths($i)->format('Y-m'))->all();
    }

    // ---------------------------------------------------------------- shop

    private static function takings(Store $store, Carbon $from, Carbon $to)
    {
        return Sale::where('store_id', $store->id)->whereNotIn('status', self::NOT_TAKINGS)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to);
    }

    private static function shopTotals(Store $store, string $month): array
    {
        [$from, $to] = self::month($month);
        $revenue = (int) self::takings($store, $from, $to)->sum('total');
        $cogs = (int) DB::table('sale_items')->join('sales', 'sales.number', '=', 'sale_items.sale_number')
            ->where('sales.store_id', $store->id)->whereNotIn('sales.status', self::NOT_TAKINGS)
            ->where('sales.created_at', '>=', $from)->where('sales.created_at', '<', $to)
            ->sum(DB::raw('sale_items.cost * sale_items.qty'));
        $expenses = (int) Expense::where('store_id', $store->id)->where('spent_on', '>=', "{$month}-01")
            ->where('spent_on', '<', Carbon::createFromFormat('Y-m-d', "{$month}-01")->addMonth()->format('Y-m-d'))->sum('amount');

        return ['month' => $month, 'revenue' => $revenue, 'costOfGoods' => $cogs, 'grossProfit' => $revenue - $cogs,
            'expenses' => $expenses, 'netProfit' => $revenue - $cogs - $expenses];
    }

    public static function shopSummary(Store $store, ?string $month): array
    {
        [$from, $to, $month] = self::month($month);
        $totals = self::shopTotals($store, $month);
        $byMethod = self::takings($store, $from, $to)->select('method', DB::raw('SUM(total) as total'), DB::raw('COUNT(*) as n'))
            ->groupBy('method')->get()->mapWithKeys(fn ($r) => [$r->method => ['total' => (int) $r->total, 'count' => (int) $r->n]]);
        $nextMonth = Carbon::createFromFormat('Y-m-d', "{$month}-01")->addMonth()->format('Y-m-d');
        $byCategory = Expense::where('store_id', $store->id)->where('spent_on', '>=', "{$month}-01")->where('spent_on', '<', $nextMonth)
            ->select('category', DB::raw('SUM(amount) as total'))->groupBy('category')->orderByDesc('total')->get()
            ->map(fn ($r) => ['category' => $r->category, 'total' => (int) $r->total])->values()->all();

        return [
            ...$totals,
            'salesCount' => self::takings($store, $from, $to)->count(),
            'byMethod' => collect(Sale::PAYMENT_METHODS)->mapWithKeys(fn ($m) => [$m => $byMethod[$m] ?? ['total' => 0, 'count' => 0]])->all(),
            'expensesByCategory' => $byCategory,
            'creditCollected' => (int) DB::table('customer_payments')->where('store_id', $store->id)
                ->where('created_at', '>=', $from)->where('created_at', '<', $to)->sum('amount'),
            'owedToYou' => (int) Customer::where('store_id', $store->id)->sum('balance'),
            'owedToSuppliers' => SupplierAccounts::totalOwed($store),
            'onlineReceived' => (int) Payout::where('store_id', $store->id)->where('status', 'paid')
                ->where('created_at', '>=', $from)->where('created_at', '<', $to)->sum('amount'),
            'onlineFees' => (int) Payout::where('store_id', $store->id)->where('status', 'paid')
                ->where('created_at', '>=', $from)->where('created_at', '<', $to)->sum('commission'),
            'history' => array_map(fn ($m) => self::shopTotals($store, $m), self::previousMonths($month, 6)),
        ];
    }

    public static function addExpense(Store $store, array $data, User $by): Expense
    {
        $date = Input::text($data, 'spentOn', true, 10, 'Choose the date.');
        $tz = config('topline.timezone');
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? Carbon::createFromFormat('Y-m-d', $date, $tz) : null;
        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw ApiError::invalid('Choose a valid date.', 'spentOn');
        }
        if ($parsed->greaterThan(now($tz)->endOfDay()) || $parsed->lessThan(now($tz)->subYears(5))) {
            throw ApiError::invalid('Choose a date that isn’t in the future.', 'spentOn');
        }
        $category = (string) ($data['category'] ?? '');
        if (! in_array($category, Expense::CATEGORIES, true)) {
            throw ApiError::invalid('Choose a category.', 'category');
        }
        $method = (string) ($data['method'] ?? 'cash');
        if (! in_array($method, Expense::METHODS, true)) {
            throw ApiError::invalid('Choose how it was paid.', 'method');
        }
        $amount = Input::integer($data, 'amount', true, 1, 'Enter the amount in shillings.');
        if ($amount > 100000000) {
            throw ApiError::invalid('That amount is too large.', 'amount');
        }
        $expense = Expense::create([
            'store_id' => $store->id, 'spent_on' => $date, 'category' => $category, 'amount' => $amount, 'method' => $method,
            'description' => Input::text($data, 'description', false, 200), 'recorded_by' => $by->id,
        ]);
        Activity::record('expense_added', "{$by->name} recorded an expense: ".Text::kes($amount)." ({$category})", $store->id, $by);

        return $expense;
    }

    public static function expenses(Store $store, string $month)
    {
        [, , $month] = self::month($month);
        $next = Carbon::createFromFormat('Y-m-d', "{$month}-01")->addMonth()->format('Y-m-d');

        return Expense::where('store_id', $store->id)->where('spent_on', '>=', "{$month}-01")->where('spent_on', '<', $next)
            ->orderByDesc('spent_on')->orderByDesc('id')->get();
    }

    public static function deleteExpense(Store $store, int|string $id, User $by): void
    {
        $expense = Expense::where('store_id', $store->id)->find($id) ?? throw ApiError::notFound('That expense doesn’t exist.');
        $expense->delete();
        Activity::record('expense_deleted', "{$by->name} removed an expense: ".Text::kes($expense->amount)." ({$expense->category})", $store->id, $by);
    }

    // ---------------------------------------------------------------- Topline

    private static function platformTotals(string $month): array
    {
        [$from, $to] = self::month($month);
        $plans = Payment::where('kind', 'subscription')->where('status', 'paid')->where('paid_at', '>=', $from)->where('paid_at', '<', $to);
        $payouts = Payout::where('status', 'paid')->where('created_at', '>=', $from)->where('created_at', '<', $to);

        return [
            'month' => $month,
            'planIncome' => (int) (clone $plans)->sum('amount'),
            'planPayments' => (clone $plans)->count(),
            'commissionIncome' => (int) (clone $payouts)->sum('commission'),
            'income' => (int) (clone $plans)->sum('amount') + (int) (clone $payouts)->sum('commission'),
        ];
    }

    public static function platformSummary(?string $month): array
    {
        [$from, $to, $month] = self::month($month);
        $orders = Payment::where('kind', 'order')->where('status', 'paid')->where('paid_at', '>=', $from)->where('paid_at', '<', $to);
        $payouts = fn (string $status) => Payout::where('status', $status)->where('created_at', '>=', $from)->where('created_at', '<', $to);
        $plans = Plan::all()->keyBy('id');
        $active = Store::where('status', 'active')->get();
        $mrr = $active->sum(function (Store $s) use ($plans) {
            $plan = $plans->get($s->plan_id);

            return ! $plan ? 0 : ($s->billing_period === 'yearly' ? (int) round($plan->price_yearly / 12) : (int) $plan->price_monthly);
        });

        return [
            ...self::platformTotals($month),
            'onlineOrderVolume' => (int) (clone $orders)->sum('amount'),
            'onlineOrders' => (clone $orders)->count(),
            'payoutsSent' => ['total' => (int) $payouts('paid')->sum('amount'), 'count' => $payouts('paid')->count()],
            'payoutsPending' => ['total' => (int) $payouts('pending')->sum('amount'), 'count' => $payouts('pending')->count()],
            'payoutsFailed' => ['total' => (int) $payouts('failed')->sum('amount'), 'count' => $payouts('failed')->count()],
            'mrr' => $mrr,
            'activeStores' => $active->count(),
            'newStores' => Store::where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
            'pastDue' => Store::where('status', 'past_due')->orderBy('next_billing_at')->get()
                ->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name, 'planId' => $s->plan_id, 'nextBillingAt' => Text::iso($s->next_billing_at)])->all(),
            'history' => array_map(fn ($m) => self::platformTotals($m), self::previousMonths($month, 6)),
        ];
    }

    // ---------------------------------------------------------------- CSV

    /** Text a spreadsheet won't run as a formula (CSV injection). */
    private static function cell(mixed $v): string
    {
        $v = (string) ($v ?? '');
        if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($v)) {
            $v = "'".$v;
        }

        return '"'.str_replace('"', '""', $v).'"';
    }

    public static function csv(array $header, iterable $rows): string
    {
        $out = "\xEF\xBB\xBF".implode(',', array_map([self::class, 'cell'], $header))."\r\n"; // BOM so Excel reads KSh symbols
        foreach ($rows as $row) {
            $out .= implode(',', array_map([self::class, 'cell'], $row))."\r\n";
        }

        return $out;
    }

    private static function local(?\DateTimeInterface $d): string
    {
        return $d ? Carbon::instance($d)->setTimezone(config('topline.timezone'))->format('Y-m-d H:i') : '';
    }

    public static function shopCsv(Store $store, string $type, ?string $month): array
    {
        [$from, $to, $month] = self::month($month);
        if ($type === 'expenses') {
            $rows = self::expenses($store, $month)->map(fn (Expense $e) => [substr((string) $e->spent_on, 0, 10), $e->category, $e->method, $e->description, $e->amount]);

            return ["{$store->slug}-expenses-{$month}.csv", self::csv(['Date', 'Category', 'Paid by', 'Description', 'Amount (KSh)'], $rows)];
        }
        if ($type !== 'sales') {
            throw ApiError::invalid('Choose sales or expenses.', 'type');
        }
        $sales = Sale::with('items')->where('store_id', $store->id)->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->orderBy('created_at')->get();
        $rows = $sales->map(fn (Sale $s) => [self::local($s->created_at), $s->code, $s->channel === 'online' ? 'Online' : 'Counter', $s->method,
            $s->status, $s->customer_name, $s->items->sum('qty'), $s->subtotal, $s->delivery_fee, $s->total,
            $s->items->sum(fn ($i) => $i->cost * $i->qty), in_array($s->status, self::NOT_TAKINGS, true) ? 'no' : 'yes']);

        return ["{$store->slug}-sales-{$month}.csv", self::csv(['Date', 'Sale', 'Channel', 'Paid by', 'Status', 'Customer', 'Items',
            'Subtotal', 'Delivery', 'Total (KSh)', 'Cost of goods (KSh)', 'Counted as takings'], $rows)];
    }

    public static function platformCsv(string $type, ?string $month): array
    {
        [$from, $to, $month] = self::month($month);
        if ($type === 'payouts') {
            $rows = Payout::with('store')->where('created_at', '>=', $from)->where('created_at', '<', $to)->orderBy('created_at')->get()
                ->map(fn (Payout $p) => [self::local($p->created_at), $p->reference, $p->store?->name, $p->gross, $p->commission, $p->amount,
                    $p->destination, $p->status, $p->provider_ref]);

            return ["topline-payouts-{$month}.csv", self::csv(['Date', 'Reference', 'Shop', 'Order total', 'Commission', 'Sent (KSh)',
                'To', 'Status', 'Provider ref'], $rows)];
        }
        if ($type !== 'payments') {
            throw ApiError::invalid('Choose payments or payouts.', 'type');
        }
        $rows = Payment::with('store')->where('created_at', '>=', $from)->where('created_at', '<', $to)->orderBy('created_at')->get()
            ->map(fn (Payment $p) => [self::local($p->created_at), $p->reference, $p->kind === 'subscription' ? 'Plan' : 'Online order',
                $p->store?->name, $p->plan_id, $p->amount, $p->status, $p->provider_ref, self::local($p->paid_at)]);

        return ["topline-payments-{$month}.csv", self::csv(['Date', 'Reference', 'Kind', 'Shop', 'Plan', 'Amount (KSh)', 'Status',
            'M-PESA receipt', 'Paid at'], $rows)];
    }
}
