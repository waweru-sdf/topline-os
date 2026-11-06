<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safaricom M-PESA Express (Daraja STK push) into Topline's own Buy Goods till.
 *  - BusinessShortCode = the till's store (head office) number, PartyB = the till number,
 *    TransactionType = CustomerBuyGoodsOnline, Password = base64(shortcode + passkey + timestamp).
 *  - The callback (/api/webhooks/mpesa/{token}) is never trusted on its own: we ask Daraja
 *    (STK query) for the result before marking anything paid.
 * Money only comes in: there are no payouts from a till.
 */
class MpesaGateway implements Gateway
{
    private const PROCESSING = '500.001.1001'; // "The transaction is being processed"

    public static function configured(): bool
    {
        $c = config('topline.mpesa');

        return $c['consumer_key'] !== '' && $c['consumer_secret'] !== '' && $c['shortcode'] !== '' && $c['passkey'] !== '' && $c['till'] !== '';
    }

    /** Secret part of the callback URL, derived from APP_KEY so nobody can guess it. */
    public static function callbackToken(): string
    {
        return substr(hash_hmac('sha256', 'mpesa-callback', (string) config('app.key')), 0, 32);
    }

    public function name(): string
    {
        return 'mpesa';
    }

    public function chargeMpesa(ChargeRequest $request): ChargeResult
    {
        $c = config('topline.mpesa');
        [$timestamp, $password] = $this->password();
        $data = $this->post('/mpesa/stkpush/v1/processrequest', [
            'BusinessShortCode' => $c['shortcode'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerBuyGoodsOnline',
            'Amount' => $request->amount,
            'PartyA' => $request->phone,
            'PartyB' => $c['till'],
            'PhoneNumber' => $request->phone,
            'CallBackURL' => rtrim((string) config('app.url'), '/').'/api/webhooks/mpesa/'.self::callbackToken(),
            'AccountReference' => substr(preg_replace('/[^A-Za-z0-9]/', '', $request->reference), 0, 12),
            'TransactionDesc' => 'Topline plan',
        ]);
        if (($data['ResponseCode'] ?? null) !== '0' || empty($data['CheckoutRequestID'])) {
            throw new ProviderException('M-PESA prompt refused: '.($data['errorMessage'] ?? $data['ResponseDescription'] ?? 'unknown'));
        }

        return new ChargeResult(ChargeResult::PENDING, requestId: $data['CheckoutRequestID']);
    }

    /** STK query: the authoritative answer for a prompt. */
    public function verify(Payment $payment): ChargeResult
    {
        // The waiting screen polls every 2 seconds; Daraja throttles queries, so ask at most every 8 seconds per payment
        // (the callback usually answers first anyway).
        if (! $payment->request_id || ! Cache::add("mpesa:query:{$payment->reference}", 1, 8)) {
            return new ChargeResult(ChargeResult::PENDING);
        }
        [$timestamp, $password] = $this->password();
        $data = $this->post('/mpesa/stkpushquery/v1/query', [
            'BusinessShortCode' => config('topline.mpesa.shortcode'),
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $payment->request_id,
        ], allowErrors: true);
        if (($data['errorCode'] ?? null) === self::PROCESSING || ! isset($data['ResultCode'])) {
            return new ChargeResult(ChargeResult::PENDING);
        }

        return match ((string) $data['ResultCode']) {
            // The query doesn't return the receipt; the callback (which triggers this check) carries it.
            '0' => new ChargeResult(ChargeResult::PAID, $payment->provider_ref),
            '1032' => new ChargeResult(ChargeResult::FAILED, message: 'The payment was cancelled on the phone.'),
            '1037' => new ChargeResult(ChargeResult::FAILED, message: 'The phone didn’t respond to the M-PESA prompt. Try again.'),
            '1' => new ChargeResult(ChargeResult::FAILED, message: 'The M-PESA payment failed: not enough balance.'),
            '2001' => new ChargeResult(ChargeResult::FAILED, message: 'The M-PESA PIN was wrong. Try again.'),
            default => new ChargeResult(ChargeResult::FAILED, message: 'The M-PESA payment didn’t go through: '.mb_substr((string) ($data['ResultDesc'] ?? ''), 0, 120)),
        };
    }

    public function transfer(TransferRequest $request): ChargeResult
    {
        return new ChargeResult(ChargeResult::FAILED, message: 'Payouts aren’t possible from a till.');
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        return hash_equals(self::callbackToken(), $signature);
    }

    /** Daraja callback → [charge, CheckoutRequestID, receipt + amount]. The caller confirms with verify(). */
    public function parseWebhook(array $payload): ?array
    {
        $cb = $payload['Body']['stkCallback'] ?? null;
        if (! is_array($cb) || empty($cb['CheckoutRequestID'])) {
            return null;
        }
        $items = collect($cb['CallbackMetadata']['Item'] ?? [])->mapWithKeys(fn ($i) => [($i['Name'] ?? '') => $i['Value'] ?? null]);

        return ['charge', (string) $cb['CheckoutRequestID'], [
            'resultCode' => (string) ($cb['ResultCode'] ?? ''),
            'receipt' => isset($items['MpesaReceiptNumber']) ? (string) $items['MpesaReceiptNumber'] : null,
            'amount' => isset($items['Amount']) ? (int) $items['Amount'] : null,
        ]];
    }

    private function password(): array
    {
        $timestamp = now('Africa/Nairobi')->format('YmdHis');

        return [$timestamp, base64_encode(config('topline.mpesa.shortcode').config('topline.mpesa.passkey').$timestamp)];
    }

    private function base(): string
    {
        return config('topline.mpesa.env') === 'sandbox' ? 'https://sandbox.safaricom.co.ke' : 'https://api.safaricom.co.ke';
    }

    /** OAuth token, cached for just under its one-hour life. */
    private function token(): string
    {
        return Cache::remember('mpesa:token', 3300, function () {
            $c = config('topline.mpesa');
            try {
                $res = Http::timeout(20)->withBasicAuth($c['consumer_key'], $c['consumer_secret'])
                    ->get($this->base().'/oauth/v1/generate', ['grant_type' => 'client_credentials']);
            } catch (Throwable $e) {
                throw new ProviderException('M-PESA unreachable: '.$e->getMessage());
            }
            $token = $res->json('access_token');
            if (! $res->ok() || ! $token) {
                throw new ProviderException('M-PESA login failed ('.$res->status().'). Check MPESA_CONSUMER_KEY and MPESA_CONSUMER_SECRET.');
            }

            return $token;
        });
    }

    private function post(string $path, array $body, bool $allowErrors = false): array
    {
        try {
            $res = Http::timeout(30)->withToken($this->token())->acceptJson()->post($this->base().$path, $body);
        } catch (Throwable $e) {
            throw new ProviderException('M-PESA unreachable: '.$e->getMessage());
        }
        $data = $res->json() ?? [];
        if (! $res->successful() && ! $allowErrors) {
            Log::warning("M-PESA {$path} -> {$res->status()}: ".mb_substr($res->body(), 0, 300));
            throw new ProviderException('M-PESA error: '.($data['errorMessage'] ?? $res->status()));
        }

        return $data;
    }
}
