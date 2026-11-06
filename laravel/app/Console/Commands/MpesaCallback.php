<?php

namespace App\Console\Commands;

use App\Services\Payments\MpesaGateway;
use Illuminate\Console\Command;

/** Prints the M-PESA callback URL (it contains a secret derived from APP_KEY) and whether prompts are set up. */
class MpesaCallback extends Command
{
    protected $signature = 'topline:mpesa-callback';

    protected $description = 'Show the M-PESA (Daraja) callback URL and whether prompts to the Till are set up';

    public function handle(): int
    {
        $this->line('Till: '.config('topline.mpesa.till').' ('.config('topline.mpesa.till_name').'), payments mode: '.config('topline.payments_mode'));
        $this->line('Callback URL: '.rtrim((string) config('app.url'), '/').'/api/webhooks/mpesa/'.MpesaGateway::callbackToken());
        MpesaGateway::configured()
            ? $this->info('Daraja keys are set: owners get an M-PESA prompt.')
            : $this->warn('Daraja keys are not set: owners pay from the M-PESA menu and send the code for an admin to confirm.');

        return self::SUCCESS;
    }
}
