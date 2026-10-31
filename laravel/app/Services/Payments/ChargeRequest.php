<?php

namespace App\Services\Payments;

class ChargeRequest
{
    public function __construct(
        public readonly string $reference, // ours, e.g. "SUB_8F2K..." or "ORD_..."
        public readonly int $amount,       // whole KES; the customer is prompted for exactly this
        public readonly string $phone,     // 2547XXXXXXXX
        public readonly string $email = '', // Paystack requires one: "<phone>@<root domain>"
        public readonly array $metadata = [],
    ) {}
}
