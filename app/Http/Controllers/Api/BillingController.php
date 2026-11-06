<?php

namespace App\Http\Controllers\Api;

use App\Http\Presenters\Json;
use App\Models\Payment;
use App\Models\Store;
use App\Services\Access;
use App\Services\Billing;
use App\Services\PaymentRecords;
use App\Services\Payments\Gateways;
use App\Services\Permissions;
use App\Support\ApiError;
use App\Support\Phone;
use App\Support\Text;
use Illuminate\Http\Request;

/**
 * Paying for a plan (sign-up and renewals), into Topline's M-PESA Till.
 *  GET  /billing/options                 the till, and whether an M-PESA prompt can be sent
 *  POST /billing/subscription/pay        sends the M-PESA prompt for the exact plan price, returns at once
 *  POST /billing/subscription/till-code  renewal paid from the M-PESA menu: the code, for Topline to check
 *  GET  /billing/payments/{ref}          the waiting screen polls this until "paid" or "failed"
 * (Sign-up with a till code sends the code with the new store: POST /stores, billing.mpesaCode.)
 */
class BillingController extends ApiController
{
    public function options()
    {
        return $this->json([
            'till' => config('topline.mpesa.till'),
            'tillName' => config('topline.mpesa.till_name'),
            'prompt' => Gateways::promptAvailable(),
            'simulated' => Gateways::isSimulation(),
        ]);
    }

    public function pay(Request $request)
    {
        $data = $this->body($request);
        [$phone, $store, $period, $planId] = $this->who($data);

        return $this->json(Json::payment(Billing::pay($planId, $period, $phone, $store)), 202);
    }

    /** A logged-in owner who paid the Till from their M-PESA menu. */
    public function tillCode(Request $request)
    {
        $data = $this->body($request);
        [$phone, $store, $period, $planId] = $this->who($data);
        if (! $store) {
            throw ApiError::unauthorized('Log in to your shop to pay for its plan.');
        }

        return $this->json(Json::payment(Billing::submitTillCode($planId, $period, $phone, $data['mpesaCode'] ?? null, $store)), 201);
    }

    /** [phone, store being paid for (null for sign-up), period, plan] */
    private function who(array $data): array
    {
        $phone = Phone::normalize($data['phone'] ?? null);
        if (! $phone) {
            throw ApiError::invalid('Enter the M-PESA number you pay with.', 'phone');
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

        return [$phone, $store, ($data['period'] ?? null) ?: ($store?->billing_period ?? 'monthly'), ($data['planId'] ?? null) ?: $store?->plan_id];
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
