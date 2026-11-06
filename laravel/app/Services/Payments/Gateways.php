<?php

namespace App\Services\Payments;

use App\Support\ApiError;

/**
 * Picks the payment provider from PAYMENTS_MODE:
 *  - mpesa:      plans are paid into Topline's own Till (prompt via Daraja when its keys are set);
 *                online orders are paid to each shop directly (Topline can't pay out from a till)
 *  - simulation: no money moves (development, demos, tests)
 *  - paystack:   Topline collects online orders and pays shops out (kept for later)
 */
class Gateways
{
    public static function mode(): string
    {
        return (string) config('topline.payments_mode');
    }

    public static function isSimulation(): bool
    {
        return self::mode() === 'simulation';
    }

    /** Can we send an M-PESA prompt at all right now? */
    public static function promptAvailable(): bool
    {
        return match (self::mode()) {
            'simulation' => true,
            'paystack' => (bool) config('topline.paystack_secret_key'),
            'mpesa' => MpesaGateway::configured(),
            default => false,
        };
    }

    /** Can Topline collect an online order and pay the shop afterwards? (Not from a till.) */
    public static function collectsOrders(): bool
    {
        return in_array(self::mode(), ['simulation', 'paystack'], true) && self::promptAvailable();
    }

    /** The provider a payment was made with, to check on it (even after PAYMENTS_MODE changed). */
    public static function named(string $name): Gateway
    {
        return match ($name) {
            'simulation' => new SimulatedGateway,
            'paystack' => new PaystackGateway,
            'mpesa' => new MpesaGateway,
            default => throw new ApiError("Unknown payment provider '{$name}'.", 500),
        };
    }

    public static function get(): Gateway
    {
        if (! self::promptAvailable()) {
            throw new ApiError('M-PESA prompts aren’t set up yet. Pay from your M-PESA menu instead.', 503);
        }

        return self::named(self::mode());
    }
}
