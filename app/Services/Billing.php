<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Store;
use App\Support\ApiError;
use App\Support\Text;
use Illuminate\Support\Carbon;

/**
 * Store subscriptions: paying for a plan, renewals, grace period and suspension.
 * Paying is two steps like any M-PESA prompt: pay() sends the prompt, the screen polls
 * /billing/payments/{ref}. For sign-up, the paid Payment is then claimed by POST /stores.
 */
class Billing
{
    public const PERIOD_DAYS = ['monthly' => 30, 'yearly' => 365];

    public static function plan(mixed $planId): Plan
    {
        $plan = is_string($planId) ? Plan::find($planId) : null;
        if (! $plan || ! $plan->active) {
            throw ApiError::invalid('Pick one of the plans.', 'planId');
        }

        return $plan;
    }

    /** Send the M-PESA prompt for a plan. With $store, paying renews/changes that store's plan. */
    public static function pay(mixed $planId, mixed $period, string $phone, ?Store $store = null): Payment
    {
        if (! is_string($period) || ! isset(self::PERIOD_DAYS[$period])) {
            throw ApiError::invalid('Choose monthly or yearly billing.', 'period');
        }
        $plan = self::plan($planId);

        return PaymentRecords::start('subscription', $plan->priceFor($period), $phone, 'SUB', 'TOPLINE',
            ['plan_id' => $plan->id, 'period' => $period, 'store_id' => $store?->id]);
    }

    /**
     * Sign-up pays first, then creates the store with that payment's reference (or the M-PESA receipt
     * the customer was shown). Claimed atomically, so one payment can never open two stores.
     */
    public static function claimSignupPayment(?string $reference, string $planId, ?string $receipt = null): Payment
    {
        $payment = $reference ? Payment::find($reference) : null;
        if (! $payment && $receipt) {
            $payment = Payment::where('provider_ref', $receipt)->where('kind', 'subscription')->first();
        }
        if ($payment && $payment->status !== Payment::PAID) {
            $payment = PaymentRecords::refresh($payment);
        }
        $claimed = $payment && Payment::whereKey($payment->reference)
            ->where(['kind' => 'subscription', 'status' => Payment::PAID, 'claimed' => false, 'plan_id' => $planId])
            ->whereNull('store_id')->update(['claimed' => true]);
        if (! $claimed) {
            throw new ApiError("We couldn't match your payment to this sign-up. Pay again or contact support.", 402, 'planId');
        }

        return $payment->refresh();
    }

    /** Called once a renewal payment is confirmed. */
    public static function applySubscriptionPayment(Payment $payment): void
    {
        $store = Store::find($payment->store_id);
        self::activate($store, $payment->plan_id, $payment->period);
        $payment->claimed = true;
        $payment->save();
        $planName = $store->plan?->name ?? $payment->plan_id;
        Activity::record('plan_paid', 'Paid '.Text::kes($payment->amount)." for the {$planName} plan ({$payment->period}), ref "
            .($payment->provider_ref ?: $payment->reference), $store->id, 'System');
    }

    /** Start or extend a paid period. Paying early adds the time on top of what's left. */
    public static function activate(Store $store, string $planId, string $period): void
    {
        $now = now();
        $start = $store->next_billing_at && $store->next_billing_at->gt($now) ? $store->next_billing_at->copy() : $now;
        $store->plan_id = $planId;
        $store->billing_period = $period;
        $store->next_billing_at = $start->addDays(self::PERIOD_DAYS[$period]);
        $store->status = Store::ACTIVE;
        $store->save();
        $store->unsetRelation('plan');
    }

    /** Daily: overdue stores go past_due (still open), then suspended after the grace period. */
    public static function sweep(?Carbon $now = null): int
    {
        $now ??= now();
        $grace = (int) config('topline.grace_days');
        $changed = 0;
        foreach (Store::whereIn('status', [Store::ACTIVE, Store::PAST_DUE])->whereNotNull('next_billing_at')->get() as $store) {
            if ($store->status === Store::ACTIVE && $store->next_billing_at->lte($now)) {
                $store->status = Store::PAST_DUE;
                $store->save();
                Activity::record('store_status', "{$store->name}'s plan payment is due (grace period started)", $store->id, 'System');
                $changed++;
            } elseif ($store->status === Store::PAST_DUE && $store->next_billing_at->copy()->addDays($grace)->lte($now)) {
                $store->status = Store::SUSPENDED;
                $store->save();
                Activity::record('store_status', "{$store->name} was suspended: plan not paid after the grace period", $store->id, 'System');
                $changed++;
            }
        }

        return $changed;
    }
}
