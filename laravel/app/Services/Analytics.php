<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Incident;
use App\Models\PageView;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Plan;
use App\Models\RequestStat;
use App\Models\Sale;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Numbers for the Topline admin Overview: stores, money, customers, traffic, health (last 30 days). */
class Analytics
{
    public const DAYS = 30;

    public static function overview(): array
    {
        $now = now();
        $since = $now->copy()->subDays(self::DAYS);
        $stores = Store::all();
        $plans = Plan::all()->keyBy('id');
        $sales = Sale::where('created_at', '>=', $since)
            ->whereNotIn('status', [Sale::AWAITING_PAYMENT, Sale::AWAITING_CONFIRMATION, Sale::CANCELLED])
            ->get(['number', 'channel', 'total']);

        return [
            'stores' => self::storesBlock($stores, $plans, $now),
            'money' => self::moneyBlock($sales, $since),
            'customers' => self::customersBlock($since),
            'traffic' => self::trafficBlock($stores),
            'health' => self::healthBlock($now),
        ];
    }

    private static function storesBlock($stores, $plans, Carbon $now): array
    {
        $byStatus = array_fill_keys(Store::STATUSES, 0);
        $signups = [];
        $mrr = 0;
        foreach ($stores as $s) {
            $byStatus[$s->status] = ($byStatus[$s->status] ?? 0) + 1;
            $day = $s->created_at?->toDateString();
            $signups[$day] = ($signups[$day] ?? 0) + 1;
            $plan = $plans->get($s->plan_id);
            if ($s->status === Store::ACTIVE && $plan) {
                $mrr += $s->billing_period === 'monthly' ? $plan->price_monthly : intdiv($plan->price_yearly, 12);
            }
        }

        return ['total' => $stores->count(), 'byStatus' => $byStatus, 'monthlyRevenue' => $mrr, 'signupsByDay' => self::series($signups, $now)];
    }

    private static function moneyBlock($sales, Carbon $since): array
    {
        $online = $sales->where('channel', Sale::ONLINE);
        $subs = (int) Payment::where('kind', 'subscription')->where('status', Payment::PAID)->where('paid_at', '>=', $since)->sum('amount');
        $rows = Payout::where('created_at', '>=', $since)->groupBy('status')
            ->get(['status', DB::raw('COUNT(*) as n'), DB::raw('COALESCE(SUM(amount), 0) as total')])->keyBy('status');
        $payouts = [];
        foreach (['paid', 'pending', 'failed'] as $status) {
            $row = $rows->get($status);
            $payouts[$status] = ['count' => (int) ($row->n ?? 0), 'amount' => (int) ($row->total ?? 0)];
        }

        return [
            'salesValue' => (int) $sales->sum('total'),
            'onlineOrders' => $online->count(),
            'onlineValue' => (int) $online->sum('total'),
            'counterSales' => $sales->count() - $online->count(),
            'subscriptionRevenue' => $subs,
            'payouts' => $payouts,
        ];
    }

    /** People buying across all shops: online buyers by phone, plus shops' credit customers. */
    private static function customersBlock(Carbon $since): array
    {
        $rows = Sale::where('channel', Sale::ONLINE)->whereNotNull('customer_phone')
            ->whereNotIn('status', [Sale::AWAITING_PAYMENT, Sale::CANCELLED])->groupBy('customer_phone')
            ->get(['customer_phone', DB::raw('COUNT(*) as n'), DB::raw('MIN(created_at) as first_at')]);

        return [
            'onlineBuyers' => $rows->count(),
            'newBuyers30' => $rows->filter(fn ($r) => $r->first_at && Carbon::parse($r->first_at, 'UTC')->gte($since))->count(),
            'repeatBuyers' => $rows->filter(fn ($r) => $r->n > 1)->count(),
            'creditCustomers' => Customer::count(),
            'owedToShops' => (int) Customer::sum('balance'),
        ];
    }

    private static function trafficBlock($stores): array
    {
        $start = now(config('topline.timezone'))->startOfDay()->subDays(self::DAYS - 1);
        $rows = PageView::where('day', '>=', $start->toDateString())->get();
        $site = [];
        $shop = [];
        $perStore = [];
        foreach ($rows as $r) {
            $day = substr((string) $r->day, 0, 10);
            if ($r->store_id === null) {
                $site[$day] = ($site[$day] ?? 0) + $r->views;
            } else {
                $shop[$day] = ($shop[$day] ?? 0) + $r->views;
                $perStore[$r->store_id] = ($perStore[$r->store_id] ?? 0) + $r->views;
            }
        }
        arsort($perStore);
        $names = $stores->keyBy('id');
        $byDay = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $d = $start->copy()->addDays($i)->toDateString();
            $byDay[] = ['date' => $d, 'site' => $site[$d] ?? 0, 'stores' => $shop[$d] ?? 0];
        }

        return [
            'byDay' => $byDay,
            'topStores' => collect($perStore)->take(8)->map(fn ($views, $id) => [
                'storeId' => $id, 'name' => $names->get($id)?->name ?? '?', 'slug' => $names->get($id)?->slug ?? '?', 'views' => $views,
            ])->values()->all(),
            'total30' => array_sum($site) + array_sum($shop),
        ];
    }

    private static function healthBlock(Carbon $now): array
    {
        $rows = RequestStat::where('hour', '>=', $now->copy()->subHours(24))->orderBy('hour')->get();
        $requests = (int) $rows->sum('requests');

        return [
            'requests24' => $requests,
            'serverErrors24' => (int) $rows->sum('server_errors'),
            'clientErrors24' => (int) $rows->sum('client_errors'),
            'avgMs' => $requests ? (int) round($rows->sum('total_ms') / $requests) : 0,
            'slowestMs' => (int) ($rows->max('slowest_ms') ?? 0),
            'openIncidents' => Incident::where('resolved', false)->count(),
            'byHour' => $rows->map(fn ($r) => ['hour' => $r->hour->utc()->format('Y-m-d\TH:i:sP'), 'requests' => $r->requests, 'errors' => $r->server_errors])->values()->all(),
        ];
    }

    private static function series(array $counts, Carbon $now): array
    {
        $start = $now->copy()->subDays(self::DAYS - 1)->startOfDay();
        $out = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $d = $start->copy()->addDays($i)->toDateString();
            $out[] = ['date' => $d, 'count' => $counts[$d] ?? 0];
        }

        return $out;
    }
}
