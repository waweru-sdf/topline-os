<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Services\Payments\ChargeRequest;
use App\Services\Payments\PaystackException;
use App\Services\Payments\PaystackGateway;
use App\Services\Payments\TransferRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Paystack adapter with Paystack's HTTP API faked: checks we send the right requests and
 * read the answers correctly, so going live is only a matter of adding keys.
 */
class PaystackAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['topline.payments_mode' => 'paystack', 'topline.paystack_secret_key' => 'sk_test_abc']);
    }

    public function test_charge_sends_mpesa_prompt_for_exact_amount(): void
    {
        Http::fake(['api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['status' => 'pay_offline', 'reference' => 'ORD_1']])]);
        $result = (new PaystackGateway)->chargeMpesa(new ChargeRequest('ORD_1', 1900, '254711222333', 'a@b.c'));
        $this->assertSame('pending', $result->status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/charge')
            && $r->header('Authorization')[0] === 'Bearer sk_test_abc'
            && $r['amount'] === 190000 && $r['currency'] === 'KES'
            && $r['mobile_money'] === ['phone' => '+254711222333', 'provider' => 'mpesa']);
    }

    public function test_verify_success_and_failure(): void
    {
        Http::fakeSequence()
            ->push(['status' => true, 'data' => ['status' => 'success', 'receipt_number' => 'TJK4H2PQ9X']])
            ->push(['status' => true, 'data' => ['status' => 'failed', 'gateway_response' => 'Insufficient funds']]);
        $payment = new Payment(['reference' => 'ORD_1']);
        $ok = (new PaystackGateway)->verify($payment);
        $this->assertSame(['paid', 'TJK4H2PQ9X'], [$ok->status, $ok->providerRef]);
        $bad = (new PaystackGateway)->verify($payment);
        $this->assertSame(['failed', 'Insufficient funds'], [$bad->status, $bad->message]);
    }

    public static function payoutMethods(): array
    {
        return [
            'till' => ['till', '5123456', '', ['mobile_money_business', 'MPTILL', '5123456']],
            'paybill' => ['paybill', '247247', '0733444555', ['mobile_money_business', 'MPPAYBILL', '247247']],
            'pochi' => ['pochi', '254722118404', '', ['mobile_money', 'MPESA', '0722118404']],
            'send money' => ['send_money', '254733902117', '', ['mobile_money', 'MPESA', '0733902117']],
        ];
    }

    /** @dataProvider payoutMethods */
    #[\PHPUnit\Framework\Attributes\DataProvider('payoutMethods')]
    public function test_transfer_to_each_payout_method(string $method, string $number, string $account, array $expected): void
    {
        Http::fakeSequence()
            ->push(['status' => true, 'data' => ['recipient_code' => 'RCP_1']])
            ->push(['status' => true, 'data' => ['status' => 'success', 'transfer_code' => 'TRF_1']]);
        $result = (new PaystackGateway)->transfer(new TransferRequest('PO_1', 650, $method, $number, $account, 'Shop'));
        $this->assertSame('paid', $result->status);
        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data())->all();
        [$recipient, $transfer] = $sent;
        $this->assertSame($expected, [$recipient['type'], $recipient['bank_code'], $recipient['account_number']]);
        $this->assertSame($account ?: null, $recipient['account_reference'] ?? null);
        $this->assertSame([65000, 'RCP_1'], [$transfer['amount'], $transfer['recipient']]);
    }

    public function test_paystack_errors_are_raised_cleanly(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);
        $this->expectException(PaystackException::class);
        $this->expectExceptionMessage('Invalid key');
        (new PaystackGateway)->chargeMpesa(new ChargeRequest('X', 1, '254711222333'));
    }

    public function test_webhook_signature_and_events(): void
    {
        $gateway = new PaystackGateway;
        $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'ORD_1', 'status' => 'success']]);
        $this->assertTrue($gateway->verifyWebhookSignature($raw, hash_hmac('sha512', $raw, 'sk_test_abc')));
        $this->assertFalse($gateway->verifyWebhookSignature($raw, 'forged'));
        [$kind, $ref, $result] = $gateway->parseWebhook(json_decode($raw, true));
        $this->assertSame(['charge', 'ORD_1', 'paid'], [$kind, $ref, $result->status]);
        $this->assertSame('failed', $gateway->parseWebhook(['event' => 'transfer.failed', 'data' => ['reference' => 'PO_1']])[2]->status);
    }

    public function test_unsigned_webhooks_are_refused_in_live_mode(): void
    {
        $this->postJson('/api/webhooks/paystack', ['event' => 'charge.success', 'data' => ['reference' => 'X']])->assertStatus(401);
    }

    public function test_order_prompt_goes_through_paystack(): void
    {
        Http::fake(['api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['status' => 'pay_offline']])]);
        $hammer = $this->product('claw-hammer-16-oz');
        $res = $this->order('mjengo', [['productId' => $hammer->id, 'qty' => 1]]);
        $res->assertStatus(201)->assertJson(['status' => 'awaiting_payment', 'payment' => ['amount' => 650]]);
        Http::assertSent(fn (Request $r) => $r['amount'] === 65000 && $r['reference'] === $res->json('payment.reference'));
    }
}
