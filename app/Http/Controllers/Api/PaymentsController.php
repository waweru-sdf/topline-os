<?php

namespace App\Http\Controllers\Api;

use App\Http\Presenters\Json;
use App\Models\Payment;
use App\Models\Payout;
use App\Services\Billing;
use App\Services\PaymentRecords;
use App\Services\Monitoring;
use App\Services\Payments\Gateways;
use App\Services\Payments\MpesaGateway;
use App\Services\Payments\PaystackGateway;
use App\Services\Payouts;
use App\Support\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** The on-screen test phone (simulation), M-PESA and Paystack callbacks, and background jobs over HTTP. */
class PaymentsController extends ApiController
{
    /** Plays the customer's part of an M-PESA prompt while PAYMENTS_MODE=simulation. */
    public function simulate(Request $request, string $reference)
    {
        if (! Gateways::isSimulation()) {
            throw ApiError::notFound('Not available.');
        }
        $payment = Payment::find($reference) ?? throw ApiError::notFound('Payment not found.');
        $data = $this->body($request);
        $action = $data['action'] ?? null;
        if (! in_array($action, ['approve', 'decline'], true)) {
            throw ApiError::invalid('Choose approve or decline.', 'action');
        }
        $pin = (string) ($data['pin'] ?? '');
        if ($action === 'approve' && ! (ctype_digit($pin) && strlen($pin) === 4)) {
            throw ApiError::invalid('Enter any 4-digit PIN (this is a simulation).', 'pin');
        }
        if ($payment->status === Payment::PENDING) {
            $payment->simulated_answer = $action;
            $payment->save();
            $payment = PaymentRecords::refresh($payment);
        }

        return $this->json(Json::payment($payment));
    }

    /**
     * Safaricom's M-PESA Express callback for a prompt into Topline's Till. The URL carries a secret
     * token; even so the result is only trusted after asking Daraja (STK query) about that prompt.
     */
    public function mpesaWebhook(Request $request, string $token)
    {
        $gateway = new MpesaGateway;
        if (! $gateway->verifyWebhookSignature('', $token)) {
            throw ApiError::notFound('Not found.');
        }
        $parsed = $gateway->parseWebhook(json_decode($request->getContent() ?: '{}', true) ?: []);
        $payment = $parsed ? Payment::where('request_id', $parsed[1])->first() : null;
        $info = $parsed[2] ?? [];
        if ($payment && ($info['resultCode'] ?? '') === '0' && $info['amount'] !== null && $info['amount'] !== $payment->amount) {
            Monitoring::recordIncident('payment', 'M-PESA amount mismatch', "{$payment->reference}: expected {$payment->amount}, got {$info['amount']}");
        } elseif ($payment) {
            if ($info['receipt'] && ! $payment->provider_ref) {
                $payment->update(['provider_ref' => $info['receipt']]); // also when the waiting screen already settled it
            }
            if ($payment->status === Payment::PENDING) {
                Cache::forget("mpesa:query:{$payment->reference}");
                PaymentRecords::refresh($payment); // asks Daraja, then settles
            }
        }

        return $this->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    /** Paystack callbacks. Always answer 200 quickly so Paystack doesn't retry forever. */
    public function paystackWebhook(Request $request)
    {
        $gateway = Gateways::isSimulation() ? Gateways::named('simulation') : new PaystackGateway;
        if (! $gateway->verifyWebhookSignature($request->getContent(), (string) $request->header('x-paystack-signature', ''))) {
            throw ApiError::unauthorized('Bad signature.');
        }
        $parsed = $gateway->parseWebhook(json_decode($request->getContent() ?: '{}', true) ?: []);
        if ($parsed) {
            [$kind, $reference, $result] = $parsed;
            if ($kind === 'charge') {
                $payment = PaymentRecords::record($reference, $result);
                Log::info("Charge webhook {$reference} -> ".($payment?->status ?? 'unknown reference'));
            } elseif ($kind === 'transfer' && ($payout = Payout::where('reference', strtoupper((string) $reference))->first()) && $payout->status !== 'paid') {
                Payouts::applyResult($payout, $result);
            }
        }

        return $this->json(['received' => true]);
    }

    /**
     * Background jobs over HTTP, for hosting without a scheduler: GET /api/cron/run?key=CRON_SECRET.
     * Settles abandoned prompts; once a day after 06:00 Kenyan time also runs the billing check.
     * (On Hostinger, `php artisan schedule:run` from Cron Jobs does the same; see routes/console.php.)
     */
    public function cron(Request $request)
    {
        $secret = (string) config('topline.cron_secret');
        $given = (string) ($request->query('key') ?: $request->header('X-Cron-Key', ''));
        if ($secret === '' || ! hash_equals($secret, $given)) {
            throw ApiError::notFound('Not found.');
        }
        $settled = PaymentRecords::sweepPending();
        $now = Carbon::now(config('topline.timezone'));
        $today = $now->toDateString();
        $billing = 0; // stores changed; the daily check runs once, after 06:00 Kenyan time
        $last = DB::table('site_settings')->where('key', '_cron.billingSweepDate')->value('value');
        if ($now->hour >= 6 && json_decode((string) $last, true) !== $today) {
            $billing = Billing::sweep();
            DB::table('site_settings')->updateOrInsert(['key' => '_cron.billingSweepDate'], ['value' => json_encode($today), 'updated_at' => now()]);
        }

        return $this->json(['paymentsSettled' => $settled, 'billingSweep' => $billing]);
    }
}
