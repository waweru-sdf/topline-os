<?php

namespace App\Services\Payments;

class TransferRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly int $amount,      // whole KES
        public readonly string $method,   // till | paybill | pochi | send_money
        public readonly string $number,   // till / paybill number, or 2547XXXXXXXX
        public readonly string $account = '', // paybill account number
        public readonly string $name = '',
        public readonly string $reason = '',
    ) {}
}
