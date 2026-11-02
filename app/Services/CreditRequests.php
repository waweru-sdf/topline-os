<?php

namespace App\Services;

use App\Models\CreditRequest;
use App\Models\Store;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Input;
use App\Support\Phone;
use App\Support\Text;

/** Stock credit: shops ask, Topline admins follow up with the lending partner. */
class CreditRequests
{
    public const MIN_AMOUNT = 5000;
    public const MAX_AMOUNT = 50000000;

    public static function create(Store $store, array $data, User $by): CreditRequest
    {
        $open = CreditRequest::where('store_id', $store->id)->whereIn('status', CreditRequest::OPEN)->first();
        if ($open) {
            throw new ApiError("We’re still processing your request {$open->reference}. We’ll reach out soon.", 409);
        }
        $amount = Input::integer($data, 'amount', true, self::MIN_AMOUNT, 'Enter an amount of at least '.Text::kes(self::MIN_AMOUNT).'.');
        if ($amount > self::MAX_AMOUNT) {
            throw ApiError::invalid('Enter an amount up to '.Text::kes(self::MAX_AMOUNT).'.', 'amount');
        }
        $purpose = (string) ($data['purpose'] ?? '');
        if (! in_array($purpose, CreditRequest::PURPOSES, true)) {
            throw ApiError::invalid('Choose what the credit is for.', 'purpose');
        }
        $period = (int) ($data['periodMonths'] ?? 0);
        if (! in_array($period, CreditRequest::PERIODS, true)) {
            throw ApiError::invalid('Choose how long you need to repay.', 'periodMonths');
        }
        $monthly = ($data['monthlySales'] ?? '') === '' ? null : Input::integer($data, 'monthlySales', false, 0);
        $phone = Phone::normalize($data['contactPhone'] ?? null) ?? throw ApiError::invalid('Enter a mobile number like 0712 345 678.', 'contactPhone');
        if (($data['consent'] ?? false) !== true) {
            throw ApiError::invalid('Tick the box to let Topline share your shop’s sales summary with the lending partner.', 'consent');
        }
        $orderId = $data['supplierOrderId'] ?? null;
        if ($orderId && ! SupplierOrder::where('store_id', $store->id)->where('id', $orderId)->exists()) {
            throw ApiError::invalid('That supplier order isn’t from your shop.', 'supplierOrderId');
        }

        $request = CreditRequest::create([
            'reference' => 'CR-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)),
            'store_id' => $store->id,
            'user_id' => $by->id,
            'amount' => $amount,
            'purpose' => $purpose,
            'period_months' => $period,
            'monthly_sales' => $monthly,
            'contact_name' => Input::text($data, 'contactName', true, 120, 'Enter who we should talk to.'),
            'contact_phone' => $phone,
            'notes' => Input::text($data, 'notes', false, 1000),
            'supplier_order_id' => $orderId ?: null,
            'status' => 'new',
            'updated_at' => now(),
        ]);
        Activity::record('credit_request', "{$store->name} asked for ".Text::kes($amount).' of stock credit', $store->id, $by);

        return $request;
    }

    public static function update(CreditRequest $request, array $data, User $admin): CreditRequest
    {
        if (array_key_exists('status', $data)) {
            if (! in_array($data['status'], CreditRequest::STATUSES, true)) {
                throw ApiError::invalid('Choose a status from the list.', 'status');
            }
            $request->status = $data['status'];
        }
        if (array_key_exists('adminNote', $data)) {
            $request->admin_note = Input::text($data, 'adminNote', false, 1000);
        }
        $request->updated_at = now();
        $request->save();
        Activity::record('credit_request', "{$admin->name} marked credit request {$request->reference} as {$request->status}", $request->store_id, $admin);

        return $request;
    }
}
