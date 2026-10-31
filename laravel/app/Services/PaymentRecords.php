<?php

namespace App\Services;

use App\Models\Payment;
use App\Services\Payments\ChargeRequest;
use App\Services\Payments\ChargeResult;
use App\Services\Payments\Gateways;
use App\Services\Payments\PaystackException;
use App\Support\ApiError;
use App\Support\Text;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creating, checking and settling Payment rows, whatever they pay for.
 *   start()   -> Payment "pending", prompt sent for the exact amount
 *   refresh() -> polled by the screen the customer is looking at (and the 5-minute sweep)
 *   record()  -> called by the Paystack webhook
 * Settling is atomic and happens once, even if a poll and the webhook arrive together.
 */
class PaymentRecords
{
    /** Create a pending Payment and send the M-PESA prompt for exactly $amount. */
    public static function start(string $kind, int $amount, string $phone, string $prefix, string $payee = 'TOPLINE', array $links = []): Payment
    {
        $gateway = Gateways::get();
        $payment = Payment::create(['reference' => Text::reference($prefix), 'kind' => $kind, 'provider' => $gateway->name(),
            'amount' => $amount, 'phone' => $phone, 'payee' => $payee, 'status' => Payment::PENDING, ...$links]);
        try {
            $result = $gateway->chargeMpesa(new ChargeRequest($payment->reference, $amount, $phone,
                $phone.'@'.config('topline.root_domain'),
                ['kind' => $kind, 'payee' => $payee, ...array_filter($links, fn ($v) => $v !== null)]));
        } catch (PaystackException $e) {
            $payment->delete();
            Monitoring::recordIncident('payment', 'M-PESA prompt could not be sent', $e->getMessage());
            throw new ApiError("We couldn't send the M-PESA prompt. Try again in a minute.", 502, 'phone');
        }
        self::apply($payment, $result);

        return $payment->refresh();
    }

    /** Ask the provider about a pending payment and record the answer (or time it out). */
    public static function refresh(Payment $payment): Payment
    {
        if ($payment->status !== Payment::PENDING) {
            return $payment;
        }
        try {
            $result = Gateways::get()->verify($payment);
        } catch (PaystackException $e) {
            Log::warning("verify {$payment->reference} failed: {$e->getMessage()}");
            $result = new ChargeResult(ChargeResult::PENDING);
        }
        $timeout = (int) config('topline.payment_timeout_minutes');
        if ($result->status === ChargeResult::PENDING && $payment->created_at->lt(now()->subMinutes($timeout))) {
            $result = new ChargeResult(ChargeResult::FAILED, message: "The M-PESA prompt wasn't answered in time. Try again.");
        }
        self::apply($payment, $result);

        return $payment->refresh();
    }

    /**
     * Re-check payments nobody is watching any more (customer closed the page, webhook lost).
     * Paid ones complete their order; unanswered ones past the timeout fail and release stock.
     */
    public static function sweepPending(int $olderThanMinutes = 2): int
    {
        $changed = 0;
        $stale = Payment::where('status', Payment::PENDING)->where('created_at', '<', now()->subMinutes($olderThanMinutes))->get();
        foreach ($stale as $payment) {
            try {
                if (self::refresh($payment)->status !== Payment::PENDING) {
                    $changed++;
                }
            } catch (Throwable $e) {
                Log::error("sweep: payment {$payment->reference}: {$e->getMessage()}");
            }
        }

        return $changed;
    }

    /** Webhook: apply a provider result to our payment by reference. */
    public static function record(?string $reference, ChargeResult $result): ?Payment
    {
        $payment = $reference ? Payment::find($reference) : null;
        if (! $payment) {
            return null;
        }
        self::apply($payment, $result);

        return $payment->refresh();
    }

    public static function describe(Payment $payment): array
    {
        return \App\Http\Presenters\Json::payment($payment);
    }

    /**
     * Settle a payment exactly once: the status only moves from "pending" with a conditional UPDATE,
     * so a poll and a webhook arriving together can't both pay the shop.
     */
    private static function apply(Payment $payment, ChargeResult $result): void
    {
        if ($result->status === ChargeResult::PENDING) {
            return;
        }
        DB::transaction(function () use ($payment, $result) {
            $changes = $result->status === ChargeResult::PAID
                ? ['status' => Payment::PAID, 'provider_ref' => $result->providerRef, 'paid_at' => now()]
                : ['status' => Payment::FAILED, 'failure_reason' => mb_substr((string) $result->message, 0, 200) ?: null];
            // A late "paid" (after the prompt timed out) still counts: the customer's money arrived.
            $from = $result->status === ChargeResult::PAID ? [Payment::PENDING, Payment::FAILED] : [Payment::PENDING];
            $won = Payment::whereKey($payment->reference)->whereIn('status', $from)->update($changes);
            if (! $won) {
                return; // someone else settled it first
            }
            $payment->refresh();
            if ($payment->status === Payment::PAID) {
                if ($payment->kind === Payment::SUBSCRIPTION && $payment->store_id) {
                    Billing::applySubscriptionPayment($payment);
                } elseif ($payment->kind === Payment::ORDER) {
                    Orders::markPaid($payment);
                }
            } elseif ($payment->kind === Payment::ORDER) {
                Orders::releaseUnpaid($payment);
            }
        });
    }
}
