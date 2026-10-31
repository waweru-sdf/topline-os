<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * Pretend M-PESA, for development and demos. No money moves.
 *
 * SIMULATION_APPROVAL=prompt (default): waits until someone answers the on-screen test phone
 * (POST /api/simulator/payments/{ref} approve|decline), like a customer entering, or not entering, their PIN.
 * SIMULATION_APPROVAL=auto: approves after SIMULATED_PAYMENT_DELAY_SECONDS (tests).
 * In both modes, numbers ending in 999 always fail ("not enough balance").
 */
class SimulatedGateway implements Gateway
{
    public function name(): string
    {
        return 'simulation';
    }

    private static function receipt(): string
    {
        return 'T'.substr(strtoupper(bin2hex(random_bytes(5))), 0, 9);
    }

    public function chargeMpesa(ChargeRequest $request): ChargeResult
    {
        Log::info("[simulated M-PESA] prompt: pay KES {$request->amount}, phone {$request->phone}, ref {$request->reference}");

        return new ChargeResult(ChargeResult::PENDING);
    }

    public function verify(Payment $payment): ChargeResult
    {
        if (str_ends_with($payment->phone, '999')) {
            return new ChargeResult(ChargeResult::FAILED, message: 'The M-PESA payment failed: not enough balance.');
        }
        if ($payment->simulated_answer === 'decline') {
            return new ChargeResult(ChargeResult::FAILED, message: 'The payment was cancelled on the phone.');
        }
        if ($payment->simulated_answer === 'approve') {
            return new ChargeResult(ChargeResult::PAID, self::receipt());
        }
        if (config('topline.simulation_approval') === 'auto') {
            $waited = $payment->created_at->diffInSeconds(now(), true);
            if ($waited >= config('topline.simulated_payment_delay_seconds')) {
                return new ChargeResult(ChargeResult::PAID, self::receipt());
            }
        }

        return new ChargeResult(ChargeResult::PENDING);
    }

    public function transfer(TransferRequest $request): ChargeResult
    {
        Log::info("[simulated payout] KES {$request->amount} to {$request->method} {$request->number} {$request->account}");

        return new ChargeResult(ChargeResult::PAID, 'TRF_'.strtoupper(bin2hex(random_bytes(4))));
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        return true; // nothing to verify in simulation
    }

    public function parseWebhook(array $payload): ?array
    {
        // Lets you fake a webhook: {"event": "charge.success", "data": {"reference": "..."}}
        $data = $payload['data'] ?? [];
        $event = $payload['event'] ?? null;
        if ($event === 'charge.success') {
            return ['charge', $data['reference'] ?? null, new ChargeResult(ChargeResult::PAID, $data['receipt_number'] ?? self::receipt())];
        }
        if ($event === 'transfer.success') {
            return ['transfer', $data['reference'] ?? null, new ChargeResult(ChargeResult::PAID, $data['transfer_code'] ?? null)];
        }

        return null;
    }
}
