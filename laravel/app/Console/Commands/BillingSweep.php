<?php

namespace App\Console\Commands;

use App\Services\Billing;
use Illuminate\Console\Command;

class BillingSweep extends Command
{
    protected $signature = 'topline:billing-sweep';

    protected $description = 'Mark overdue stores "payment due", then suspend them after the grace period (daily)';

    public function handle(): int
    {
        $this->info(Billing::sweep().' store(s) changed status.');

        return self::SUCCESS;
    }
}
