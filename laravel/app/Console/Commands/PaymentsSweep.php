<?php

namespace App\Console\Commands;

use App\Services\PaymentRecords;
use Illuminate\Console\Command;

class PaymentsSweep extends Command
{
    protected $signature = 'topline:payments-sweep';

    protected $description = 'Settle M-PESA prompts nobody is waiting on any more and put unpaid stock back (every 5 minutes)';

    public function handle(): int
    {
        $this->info(PaymentRecords::sweepPending().' payment(s) settled.');

        return self::SUCCESS;
    }
}
