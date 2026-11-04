<?php

namespace App\Services;

use App\Models\Store;
use App\Models\SupplierAccount;
use App\Models\SupplierAccountEntry;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Input;
use App\Support\Phone;
use App\Support\Text;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Supplier balances: what the shop owes the suppliers it buys from on credit.
 * A bill adds to the balance (goods taken, to pay later); a payment takes it down.
 */
class SupplierAccounts
{
    public static function list(Store $store): array
    {
        $accounts = SupplierAccount::where('store_id', $store->id)->orderByDesc('balance')->orderBy('name')->get();
        $due = SupplierAccountEntry::whereIn('account_id', $accounts->pluck('id'))->where('kind', SupplierAccountEntry::BILL)
            ->whereNotNull('due_on')->selectRaw('account_id, MIN(due_on) as next_due')->groupBy('account_id')->pluck('next_due', 'account_id');

        return [
            'totalOwed' => (int) $accounts->sum(fn ($a) => max(0, $a->balance)),
            'accounts' => $accounts->map(fn ($a) => [...self::json($a), 'nextDue' => $a->balance > 0 ? ($due[$a->id] ?? null) : null])->values()->all(),
        ];
    }

    public static function find(Store $store, mixed $id): SupplierAccount
    {
        $account = is_string($id) ? SupplierAccount::find($id) : null;
        if (! $account || $account->store_id !== $store->id) {
            throw ApiError::notFound('That supplier isn’t in your list.');
        }

        return $account;
    }

    public static function save(Store $store, array $data, ?SupplierAccount $account = null): SupplierAccount
    {
        $name = Input::text($data, 'name', true, 120, 'Enter the supplier’s name.');
        $taken = SupplierAccount::where('store_id', $store->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($account, fn ($q) => $q->where('id', '!=', $account->id))->exists();
        if ($taken) {
            throw ApiError::invalid('That supplier is already in your list.', 'name');
        }
        $phone = trim((string) ($data['phone'] ?? ''));
        $phone = $phone === '' ? '' : (Phone::normalize($phone) ?? throw ApiError::invalid('Enter a number like 0712 345 678, or leave it empty.', 'phone'));
        $account ??= new SupplierAccount(['store_id' => $store->id, 'balance' => 0]);
        $account->fill(['name' => $name, 'phone' => $phone, 'note' => Input::text($data, 'note', false, 300)])->save();

        return $account;
    }

    public static function addEntry(Store $store, SupplierAccount $account, array $data, User $by): SupplierAccount
    {
        $kind = (string) ($data['kind'] ?? '');
        if (! in_array($kind, [SupplierAccountEntry::BILL, SupplierAccountEntry::PAYMENT], true)) {
            throw ApiError::invalid('Choose a bill or a payment.', 'kind');
        }
        $amount = Input::integer($data, 'amount', true, 1, 'Enter an amount above 0.');
        if ($amount > 100_000_000) {
            throw ApiError::invalid('Enter a smaller amount.', 'amount');
        }
        $date = self::date($data['date'] ?? null, 'date') ?? Carbon::now(config('topline.timezone'))->toDateString();
        $due = $kind === SupplierAccountEntry::BILL ? self::date($data['dueOn'] ?? null, 'dueOn') : null;

        DB::transaction(function () use ($account, $kind, $amount, $date, $due, $data, $by) {
            SupplierAccountEntry::create(['account_id' => $account->id, 'kind' => $kind, 'amount' => $amount, 'entry_date' => $date,
                'due_on' => $due, 'note' => Input::text($data, 'note', false, 300), 'user_name' => $by->name]);
            SupplierAccount::whereKey($account->id)->increment('balance', $kind === SupplierAccountEntry::BILL ? $amount : -$amount);
        });
        $verb = $kind === SupplierAccountEntry::BILL ? 'recorded a bill of' : 'recorded a payment of';
        Activity::record('supplier_balance', "{$by->name} {$verb} ".Text::kes($amount)." for {$account->name}", $store->id, $by);

        return $account->refresh();
    }

    /** Only a supplier with nothing owed either way can be removed (the history goes with it). */
    public static function delete(SupplierAccount $account): void
    {
        if ($account->balance !== 0) {
            throw ApiError::invalid('Settle the balance before removing this supplier.');
        }
        $account->delete();
    }

    public static function totalOwed(Store $store): int
    {
        return (int) SupplierAccount::where('store_id', $store->id)->where('balance', '>', 0)->sum('balance');
    }

    public static function json(SupplierAccount $a, bool $withEntries = false): array
    {
        $data = ['id' => $a->id, 'name' => $a->name, 'phone' => $a->phone ? Phone::display($a->phone) : null, 'note' => $a->note, 'balance' => $a->balance];
        if ($withEntries) {
            $data['entries'] = $a->entries()->limit(200)->get()->map(fn ($e) => [
                'id' => $e->id, 'kind' => $e->kind, 'amount' => $e->amount, 'date' => $e->entry_date->format('Y-m-d'),
                'dueOn' => $e->due_on?->format('Y-m-d'), 'note' => $e->note, 'by' => $e->user_name,
            ])->values()->all();
        }

        return $data;
    }

    private static function date(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? Carbon::createFromFormat('!Y-m-d', $value) : false;
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw ApiError::invalid('Choose a date.', $field);
        }

        return $value;
    }
}
