<?php

namespace App\Http\Controllers\Api;

use App\Http\Presenters\Json;
use App\Models\Payment;
use App\Models\Payout;
use App\Services\Billing;
use App\Services\PaymentRecords;
use App\Services\Payments\Gateways;
use App\Services\Payouts;
use App\Support\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** The on-screen test phone (simulation), Paystack webhooks, and background jobs over HTTP. */
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

    /** Paystack callbacks. Always answer 200 quickly so Paystack doesn't retry forever. */
    public function paystackWebhook(Request $request)
    {
        $gateway = Gateways::get();
        if (! $gateway->verifyWebhookSignature($request->getContent(), (string) $request->header('x-paystack-signature', ''))) {
            throw ApiError::unauthorized('Bad signature.');
        }
        $parsed = $gateway->parseWebhook(json_decode($request->getContent() ?: '{}', true) ?: []);
        if ($parsed) {
            [$kind, $reference, $result] = $parsed;
            if ($kind === 'charge') {
                $payment = PaymentRecords::record($reference, $result);
                Log::info("Charge webhook {$reference} -> ".($payment?->status ?? 'unknown reference'));
            } elseif ($kind === 'transfer' && ($payout = Payout::where('reference', $reference)->first()) && $payout->status !== 'paid') {
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
        $billing = 'already ran today';
        $last = DB::table('site_settings')->where('key', '_cron.billingSweepDate')->value('value');
        if ($now->hour >= 6 && json_decode((string) $last, true) !== $today) {
            $billing = Billing::sweep();
            DB::table('site_settings')->updateOrInsert(['key' => '_cron.billingSweepDate'], ['value' => json_encode($today), 'updated_at' => now()]);
        }

        return $this->json(['paymentsSettled' => $settled, 'billingSweep' => $billing]);
    }
}
