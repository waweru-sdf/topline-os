<?php

namespace App\Services\Payments;

use App\Support\ApiError;

/** Picks the payment provider from PAYMENTS_MODE ("simulation" or "paystack"). */
class Gateways
{
    public static function isSimulation(): bool
    {
        return config('topline.payments_mode') === 'simulation';
    }

    public static function get(): Gateway
    {
        $mode = config('topline.payments_mode');
        if ($mode === 'paystack' && ! config('topline.paystack_secret_key')) {
            throw new ApiError("Payments aren't set up yet. Contact Topline support.", 503);
        }

        return match ($mode) {
            'simulation' => new SimulatedGateway,
            'paystack' => new PaystackGateway,
            default => throw new ApiError("Unknown PAYMENTS_MODE '{$mode}'.", 500),
        };
    }
}
