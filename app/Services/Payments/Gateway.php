<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * The contract every payment provider implements. The rest of the app only talks to a Gateway,
 * so moving from the simulation to Paystack doesn't touch routes or models.
 */
interface Gateway
{
    public function name(): string;

    /** Send an M-PESA prompt (STK push) for exactly $request->amount. Usually PENDING. */
    public function chargeMpesa(ChargeRequest $request): ChargeResult;

    /** Ask the provider for the latest status of an existing payment. */
    public function verify(Payment $payment): ChargeResult;

    /** Send money to a shop's till / paybill / Pochi / phone. */
    public function transfer(TransferRequest $request): ChargeResult;

    public function verifyWebhookSignature(string $rawBody, string $signature): bool;

    /** [kind ("charge"|"transfer"), our reference, ChargeResult], or null to ignore. */
    public function parseWebhook(array $payload): ?array;
}
