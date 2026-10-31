<?php

namespace App\Services\Payments;

class ChargeResult
{
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,          // pending | paid | failed
        public readonly ?string $providerRef = null, // M-PESA receipt, e.g. "TJK4H2PQ9X"
        public readonly ?string $message = null,     // why it failed, in plain words
    ) {}
}
