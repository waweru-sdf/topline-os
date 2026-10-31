<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Paystack (Kenya): M-PESA prompts for plans and orders, transfers to shops, webhooks.
 *
 * Ready to use. To switch on, in .env:
 *     PAYMENTS_MODE=paystack
 *     PAYSTACK_SECRET_KEY=sk_test_...   (sk_live_... when going live)
 *     PAYSTACK_PUBLIC_KEY=pk_test_...
 * and in the Paystack dashboard set the webhook URL to https://<your domain>/api/webhooks/paystack
 *
 * Every Paystack detail is in this file, so if Paystack changes anything, only this file changes.
 * Docs: https://paystack.com/docs/payments/payment-channels/ and
 *       https://paystack.com/docs/transfers/creating-transfer-recipients/
 */
class PaystackGateway implements Gateway
{
    public const BASE_URL = 'https://api.paystack.co';
    public const TIMEOUT = 20;

    // How Paystack identifies M-PESA destinations for transfer recipients in Kenya: [type, bank_code].
    // Confirm with Paystack support for your account before going live.
    public const RECIPIENT = [
        'send_money' => ['mobile_money', 'MPESA'],              // M-PESA wallet (phone number)
        'pochi' => ['mobile_money', 'MPESA'],                   // Pochi la Biashara is paid like a wallet number
        'till' => ['mobile_money_business', 'MPTILL'],          // Buy Goods till
        'paybill' => ['mobile_money_business', 'MPPAYBILL'],    // Paybill + account number
    ];

    // Charge statuses that mean "the customer has the prompt, keep waiting".
    private const WAITING = ['pending', 'pay_offline', 'send_otp', 'processing', 'ongoing'];

    public function name(): string
    {
        return 'paystack';
    }

    private function request(string $method, string $path, array $json = []): array
    {
        try {
            $client = Http::withToken((string) config('topline.paystack_secret_key'))->acceptJson()->timeout(self::TIMEOUT);
            $res = $method === 'GET' ? $client->get(self::BASE_URL.$path) : $client->post(self::BASE_URL.$path, $json);
            $payload = $res->json();
        } catch (ConnectionException $e) {
            throw new PaystackException("Couldn't reach Paystack: ".$e->getMessage(), 0, $e);
        }
        if (! is_array($payload)) {
            throw new PaystackException('Paystack error '.$res->status());
        }
        if (empty($payload['status'])) {
            throw new PaystackException($payload['message'] ?? 'Paystack error '.$res->status());
        }

        return $payload['data'] ?? [];
    }

    public function chargeMpesa(ChargeRequest $request): ChargeResult
    {
        $data = $this->request('POST', '/charge', [
            'email' => $request->email,
            'amount' => $request->amount * 100, // smallest unit (cents)
            'currency' => 'KES',
            'reference' => $request->reference,
            'mobile_money' => ['phone' => '+'.$request->phone, 'provider' => 'mpesa'],
            'metadata' => $request->metadata,
        ]);

        return self::chargeResult($data);
    }

    public function verify(Payment $payment): ChargeResult
    {
        try {
            $data = $this->request('GET', '/transaction/verify/'.rawurlencode($payment->reference));
        } catch (PaystackException $e) {
            // A charge still waiting for the PIN may not be a full transaction yet.
            Log::info("verify {$payment->reference}: {$e->getMessage()}");

            return new ChargeResult(ChargeResult::PENDING);
        }

        return self::chargeResult($data);
    }

    public static function chargeResult(array $data): ChargeResult
    {
        $status = strtolower((string) ($data['status'] ?? ''));
        if ($status === 'success') {
            $receipt = $data['receipt_number'] ?? ($data['authorization']['receiver_bank_account_number'] ?? null);

            return new ChargeResult(ChargeResult::PAID, $receipt ?: (string) ($data['id'] ?? ''));
        }
        if ($status === '' || in_array($status, self::WAITING, true)) {
            return new ChargeResult(ChargeResult::PENDING);
        }

        return new ChargeResult(ChargeResult::FAILED, message: $data['gateway_response'] ?? "The M-PESA payment didn't go through.");
    }

    public function transfer(TransferRequest $request): ChargeResult
    {
        [$type, $bankCode] = self::RECIPIENT[$request->method];
        $accountNumber = in_array($request->method, ['till', 'paybill'], true) ? $request->number : '0'.substr($request->number, 3);
        $recipient = $this->request('POST', '/transferrecipient', array_filter([
            'type' => $type,
            'name' => $request->name ?: 'Topline shop',
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'currency' => 'KES',
            'account_reference' => $request->method === 'paybill' ? $request->account : null,
        ], fn ($v) => $v !== null));
        $data = $this->request('POST', '/transfer', [
            'source' => 'balance',
            'amount' => $request->amount * 100,
            'recipient' => $recipient['recipient_code'] ?? null,
            'reference' => $request->reference,
            'reason' => mb_substr($request->reason, 0, 100),
            'currency' => 'KES',
        ]);
        $status = strtolower((string) ($data['status'] ?? ''));
        if ($status === 'success') {
            return new ChargeResult(ChargeResult::PAID, $data['transfer_code'] ?? null);
        }
        if (in_array($status, ['failed', 'reversed', 'rejected'], true)) {
            return new ChargeResult(ChargeResult::FAILED, message: $data['reason'] ?? 'Transfer failed.');
        }

        return new ChargeResult(ChargeResult::PENDING, $data['transfer_code'] ?? null); // final result comes by webhook
    }

    /** Paystack signs webhooks with HMAC-SHA512 of the raw body using the secret key. */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $key = (string) config('topline.paystack_secret_key');
        if ($key === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $rawBody, $key), $signature);
    }

    public function parseWebhook(array $payload): ?array
    {
        $event = $payload['event'] ?? '';
        $data = $payload['data'] ?? [];
        $reference = $data['reference'] ?? null;

        return match ($event) {
            'charge.success' => ['charge', $reference, self::chargeResult([...$data, 'status' => 'success'])],
            'charge.failed' => ['charge', $reference, new ChargeResult(ChargeResult::FAILED, message: $data['gateway_response'] ?? null)],
            'transfer.success' => ['transfer', $reference, new ChargeResult(ChargeResult::PAID, $data['transfer_code'] ?? null)],
            'transfer.failed', 'transfer.reversed' => ['transfer', $reference, new ChargeResult(ChargeResult::FAILED, message: $data['reason'] ?? $event)],
            default => null,
        };
    }
}
