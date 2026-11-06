<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Sale;
use App\Models\Store;
use App\Services\Payments\MpesaGateway;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Plans paid into Topline's Till: the M-PESA code checked by an admin, and the Daraja prompt. */
class TillPaymentsTest extends TestCase
{
    private const OWNER = ['name' => 'Grace Njeri', 'phone' => '0722 765 432', 'password' => 'duka-yangu-24'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['topline.payments_mode' => 'mpesa', 'topline.mpesa.till' => '987715', 'topline.mpesa.till_name' => 'Topline',
            'topline.mpesa.consumer_key' => '', 'topline.mpesa.consumer_secret' => '', 'topline.mpesa.shortcode' => '', 'topline.mpesa.passkey' => '']);
    }

    private function darajaOn(): void
    {
        config(['topline.mpesa.consumer_key' => 'k', 'topline.mpesa.consumer_secret' => 's', 'topline.mpesa.shortcode' => '5551234', 'topline.mpesa.passkey' => 'pk']);
    }

    private function signUpWithCode(string $code = 'SKL4ABC12Q'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/stores', [
            'owner' => self::OWNER,
            'business' => ['name' => 'Njeri Hardware', 'county' => 'Kiambu', 'town' => 'Ruiru'],
            'planId' => 'starter', 'slug' => 'njeri-hardware',
            'billing' => ['period' => 'monthly', 'mpesaCode' => strtolower($code), 'phone' => '0722 765 432'],
        ]);
    }

    public function test_options_show_the_till_and_no_prompt_without_daraja_keys(): void
    {
        $this->getJson('/api/billing/options')->assertOk()->assertJson(['till' => '987715', 'tillName' => 'Topline', 'prompt' => false, 'simulated' => false]);
        $this->postJson('/api/billing/subscription/pay', ['phone' => '0722 111 222', 'planId' => 'starter', 'signup' => true])->assertStatus(503);
    }

    public function test_signup_with_code_waits_for_admin_then_goes_live(): void
    {
        $res = $this->signUpWithCode()->assertCreated();
        $this->assertSame('draft', $res->json('store.status'));
        $this->assertSame(['code' => 'SKL4ABC12Q', 'status' => 'checking'], array_intersect_key($res->json('store.billing.tillCode'), ['code' => 1, 'status' => 1]));

        $admin = $this->admin();
        $this->getJson('/api/admin/badges', $admin['headers'])->assertJson(['paymentChecks' => 1]);
        $checks = $this->getJson('/api/admin/payments?status=checking', $admin['headers'])->assertOk()->json();
        $this->assertCount(1, $checks);
        $this->assertSame(['SKL4ABC12Q', 'till_code', 'monthly'], [$checks[0]['mpesaRef'], $checks[0]['method'], $checks[0]['period']]);

        $this->postJson("/api/admin/payments/{$checks[0]['reference']}/review", ['action' => 'approve'], $admin['headers'])->assertOk()->assertJson(['status' => 'paid']);
        $store = Store::where('slug', 'njeri-hardware')->first();
        $this->assertSame('active', $store->status);
        $this->assertNotNull($store->next_billing_at);
        $this->postJson("/api/admin/payments/{$checks[0]['reference']}/review", ['action' => 'approve'], $admin['headers'])->assertStatus(422);
    }

    public function test_used_or_badly_typed_codes_are_refused(): void
    {
        $this->signUpWithCode('SKL4ABC12')->assertStatus(422)->assertJson(['field' => 'mpesaCode']);
        $this->signUpWithCode()->assertCreated();
        $again = $this->postJson('/api/stores', ['owner' => [...self::OWNER, 'phone' => '0722 000 999'], 'business' => ['name' => 'Other'],
            'planId' => 'starter', 'slug' => 'other-shop', 'billing' => ['mpesaCode' => 'SKL4ABC12Q']]);
        $again->assertStatus(422)->assertJson(['field' => 'mpesaCode']);
    }

    public function test_rejected_code_leaves_store_closed_and_owner_can_send_another(): void
    {
        $this->signUpWithCode()->assertCreated();
        $ref = Payment::where('provider_ref', 'SKL4ABC12Q')->value('reference');
        $this->postJson("/api/admin/payments/{$ref}/review", ['action' => 'reject', 'note' => 'Not in the statement.'], $this->admin()['headers'])
            ->assertOk()->assertJson(['status' => 'failed']);
        $this->assertSame('draft', Store::where('slug', 'njeri-hardware')->value('status'));

        $owner = $this->login('0722 765 432', self::OWNER['password']);
        $store = $this->getJson("/api/vendor/stores/{$owner['storeId']}", $owner['headers'])->json();
        $this->assertSame(['failed', 'Not in the statement.'], [$store['billing']['tillCode']['status'], $store['billing']['tillCode']['reason']]);

        $this->postJson('/api/billing/subscription/till-code', ['phone' => '0722 765 432', 'mpesaCode' => 'TAB9XYZ77K'], $owner['headers'])
            ->assertCreated()->assertJson(['status' => 'checking']);
        $this->postJson('/api/billing/subscription/till-code', ['phone' => '0722 765 432', 'mpesaCode' => 'TAB9XYZ77L'], $owner['headers'])
            ->assertStatus(422); // one code at a time
    }

    public function test_renewal_by_code_needs_a_logged_in_owner(): void
    {
        $this->postJson('/api/billing/subscription/till-code', ['phone' => '0712 345 678', 'mpesaCode' => 'TAB9XYZ77K'])->assertStatus(401);
        $before = $this->store()->next_billing_at;
        $owner = $this->owner();
        $pay = $this->postJson('/api/billing/subscription/till-code', ['phone' => '0712 345 678', 'mpesaCode' => 'TAB9XYZ77K', 'planId' => 'pro'], $owner['headers'])->assertCreated();
        $this->postJson("/api/admin/payments/{$pay->json('reference')}/review", ['action' => 'approve'], $this->admin()['headers'])->assertOk();
        $this->assertSame('pro', $this->store()->plan_id);
        $this->assertTrue($this->store()->next_billing_at->gt($before));
    }

    public function test_daraja_prompt_goes_to_the_till_and_settles_from_the_callback(): void
    {
        $this->darajaOn();
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            '*/stkpush/v1/processrequest' => Http::response(['ResponseCode' => '0', 'CheckoutRequestID' => 'ws_CO_1']),
            '*/stkpushquery/v1/query' => Http::response(['ResultCode' => '0', 'ResultDesc' => 'ok']),
        ]);
        $this->getJson('/api/billing/options')->assertJson(['prompt' => true]);
        $pay = $this->postJson('/api/billing/subscription/pay', ['phone' => '0722 111 222', 'planId' => 'starter', 'signup' => true])->assertStatus(202);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), 'processrequest')
            && $r['TransactionType'] === 'CustomerBuyGoodsOnline' && $r['PartyB'] === '987715' && $r['BusinessShortCode'] === '5551234'
            && $r['PartyA'] === '254722111222' && $r['Amount'] === Payment::find($pay->json('reference'))->amount
            && str_ends_with($r['CallBackURL'], '/api/webhooks/mpesa/'.MpesaGateway::callbackToken()));

        $callback = ['Body' => ['stkCallback' => ['CheckoutRequestID' => 'ws_CO_1', 'ResultCode' => 0, 'CallbackMetadata' => ['Item' => [
            ['Name' => 'Amount', 'Value' => Payment::find($pay->json('reference'))->amount], ['Name' => 'MpesaReceiptNumber', 'Value' => 'TKQ1AB2CD3'],
        ]]]]];
        $this->postJson('/api/webhooks/mpesa/wrong-token', $callback)->assertNotFound();
        $this->postJson('/api/webhooks/mpesa/'.MpesaGateway::callbackToken(), $callback)->assertOk()->assertJson(['ResultCode' => 0]);
        $paid = Payment::find($pay->json('reference'));
        $this->assertSame(['paid', 'TKQ1AB2CD3'], [$paid->status, $paid->provider_ref]);
    }

    public function test_callback_with_wrong_amount_is_not_trusted(): void
    {
        $this->darajaOn();
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*/stkpush/v1/processrequest' => Http::response(['ResponseCode' => '0', 'CheckoutRequestID' => 'ws_CO_2']),
            '*/stkpushquery/v1/query' => Http::response(['ResultCode' => '1032', 'ResultDesc' => 'Cancelled']),
        ]);
        $ref = $this->postJson('/api/billing/subscription/pay', ['phone' => '0722 111 222', 'planId' => 'starter', 'signup' => true])->json('reference');
        $this->postJson('/api/webhooks/mpesa/'.MpesaGateway::callbackToken(), ['Body' => ['stkCallback' => ['CheckoutRequestID' => 'ws_CO_2', 'ResultCode' => 0,
            'CallbackMetadata' => ['Item' => [['Name' => 'Amount', 'Value' => 1], ['Name' => 'MpesaReceiptNumber', 'Value' => 'TKQ1AB2CD9']]]]]])->assertOk();
        $this->assertSame('pending', Payment::find($ref)->status);
        $this->assertSame('failed', $this->getJson("/api/billing/payments/{$ref}")->json('status')); // the query says cancelled
    }

    public function test_online_orders_pay_the_shop_directly_or_on_handover(): void
    {
        $modes = $this->getJson('/api/storefront/mjengo')->json('store.payments.modes');
        $this->assertNotContains('prompt', $modes);
        $this->assertContains('later', $modes);
        $item = ['productId' => $this->product('d12-deformed-bar-y12')->id, 'qty' => 2];
        $this->order('mjengo', [$item], ['paymentMode' => 'prompt'])->assertStatus(422);

        $later = $this->order('mjengo', [$item], ['paymentMode' => 'later'])->assertCreated();
        $this->assertNull($later->json('payment'));
        $order = Sale::where('store_id', $this->store()->id)->where('number', Sale::numberFromCode($later->json('id')))->first();
        $this->assertSame([Sale::AWAITING_CONFIRMATION, null], [$order->status, $order->paid_at]);

        $owner = $this->owner();
        foreach (['new', 'confirmed', 'completed'] as $status) {
            $this->patchJson("/api/vendor/stores/{$owner['storeId']}/sales/{$order->code}", ['status' => $status], $owner['headers'])->assertOk();
            $this->assertSame($status !== 'completed', $order->fresh()->paid_at === null);
        }
    }
}
