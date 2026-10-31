<?php

namespace App\Http\Controllers\Api;

use App\Http\Presenters\Json;
use App\Models\Payment;
use App\Models\Store;
use App\Services\Access;
use App\Services\Billing;
use App\Services\PaymentRecords;
use App\Services\Permissions;
use App\Support\ApiError;
use App\Support\Phone;
use App\Support\Text;
use Illuminate\Http\Request;

/**
 * Paying for a plan (sign-up and renewals).
 *  POST /billing/subscription/pay  sends the M-PESA prompt for the exact plan price, returns at once
 *  GET  /billing/payments/{ref}    the waiting screen polls this until "paid" or "failed"
 */
class BillingController extends ApiController
{
    public function pay(Request $request)
    {
        $data = $this->body($request);
        $phone = Phone::normalize($data['phone'] ?? null);
        if (! $phone) {
            throw ApiError::invalid('Enter the M-PESA number to charge.', 'phone');
        }
        // {"signup": true}: paying for a NEW store. Otherwise a logged-in owner renews their own store.
        $store = null;
        $user = ! empty($data['signup']) ? null : Access::currentUser(optional: true);
        if ($user && ! $user->isPlatformAdmin() && ($membership = $user->primaryMembership())) {
            if (! in_array(Permissions::MANAGE_BILLING, Permissions::for($membership->role), true)) {
                throw ApiError::forbidden('Only the shop owner can pay for the plan.');
            }
            $store = $membership->store;
        }
        $period = ($data['period'] ?? null) ?: ($store?->billing_period ?? 'monthly');
        $planId = ($data['planId'] ?? null) ?: $store?->plan_id;

        return $this->json(Json::payment(Billing::pay($planId, $period, $phone, $store)), 202);
    }

    public function status(string $reference)
    {
        $payment = Payment::find($reference);
        if (! $payment || $payment->kind !== 'subscription') {
            throw ApiError::notFound('Payment not found.');
        }
        $payment = PaymentRecords::refresh($payment);
        $data = Json::payment($payment);
        if ($payment->store_id) {
            $data['nextBillingAt'] = Text::iso(Store::find($payment->store_id)?->next_billing_at);
        }

        return $this->json($data);
    }
}
