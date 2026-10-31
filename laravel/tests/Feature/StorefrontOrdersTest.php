<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Payout;
use App\Models\Product;
use App\Models\Sale;
use App\Services\PaymentRecords;
use Tests\TestCase;

/** Storefront, delivery fees, paying the shop, overselling, prompt answers, sweeps and payouts. */
class StorefrontOrdersTest extends TestCase
{
    private function saleNumber(string $code): int
    {
        return (int) substr($code, 2);
    }

    public function test_storefront_hides_buying_prices(): void
    {
        $res = $this->getJson('/api/storefront/mjengo');
        $res->assertOk()->assertJson(['store' => ['name' => 'Mjengo Hardware']]);
        foreach ($res->json('products') as $p) {
            $this->assertArrayNotHasKey('buyPrice', $p);
        }
    }

    public function test_unknown_store_is_404(): void
    {
        $this->getJson('/api/storefront/no-such-shop')->assertStatus(404);
    }

    public function test_suspended_store_shows_closed(): void
    {
        $res = $this->getJson('/api/storefront/rift-timber');
        $this->assertSame('suspended', $res->json('store.status'));
        $this->assertSame([], $res->json('products'));
        $this->order('rift-timber', [['productId' => 'x', 'qty' => 1]])->assertStatus(409);
    }

    public function test_order_is_paid_by_simulated_mpesa_and_reduces_stock(): void
    {
        $product = $this->product('d12-deformed-bar-y12');
        $before = $product->stock;
        $res = $this->order('mjengo', [['productId' => $product->id, 'qty' => 6]], ['delivery' => ['type' => 'pickup']]);
        $res->assertStatus(201)->assertJson(['status' => 'awaiting_payment', 'subtotal' => 6 * 1540, 'deliveryFee' => 0]);
        $this->assertSame($res->json('total'), $res->json('payment.amount'));
        $this->assertSame('MJENGO HARDWARE', $res->json('payment.payee'));
        $this->assertArrayNotHasKey('cost', $res->json('items.0'));
        $this->assertSame($before - 6, $product->fresh()->stock); // reserved as soon as the order is placed

        $paid = $this->getJson("/api/storefront/mjengo/orders/{$res->json('id')}/payment")->json();
        $this->assertSame('paid', $paid['status']);
        $this->assertNotEmpty($paid['mpesaRef']);
    }

    public function test_failed_payment_releases_stock(): void
    {
        $product = $this->product('claw-hammer-16-oz');
        $before = $product->stock;
        $order = $this->postJson('/api/storefront/mjengo/orders', ['customer' => ['name' => 'B', 'phone' => '0711 222 999'], 'items' => [['productId' => $product->id, 'qty' => 1]]])->json();
        $res = $this->getJson("/api/storefront/mjengo/orders/{$order['id']}/payment")->json();
        $this->assertSame('failed', $res['status']);
        $this->assertStringContainsString('balance', $res['failureReason']);
        $this->assertSame($before, $product->fresh()->stock);
    }

    public function test_products_from_another_store_cannot_be_ordered(): void
    {
        $stray = Product::create(['store_id' => $this->store('coast-steel')->id, 'slug' => 'x', 'name' => 'X', 'category_id' => 'tools', 'unit' => 'piece', 'sell_price' => 10]);
        $this->order('mjengo', [['productId' => $stray->id, 'qty' => 1]])->assertStatus(409);
    }

    public function test_quote_request(): void
    {
        $res = $this->postJson('/api/storefront/mjengo/quotes', ['name' => 'Site A', 'phone' => '0711222333', 'items' => '60 bags cement']);
        $res->assertStatus(201);
        $this->assertStringStartsWith('Q-', $res->json('reference'));
    }

    public function test_delivery_zone_fee_is_added_to_the_prompt(): void
    {
        $hammer = $this->product('claw-hammer-16-oz');
        $res = $this->order('mjengo', [['productId' => $hammer->id, 'qty' => 2]], ['delivery' => ['type' => 'delivery', 'zoneId' => 'z2', 'address' => 'EPZ gate B']]);
        $res->assertStatus(201)->assertJson(['subtotal' => 1300, 'deliveryFee' => 600, 'total' => 1900, 'delivery' => ['zone' => 'EPZ / Athi River'], 'payment' => ['amount' => 1900]]);
    }

    public function test_free_delivery_over_threshold(): void
    {
        $cement = $this->product('savannah-cement-42-5r');
        $res = $this->order('mjengo', [['productId' => $cement->id, 'qty' => 70]], ['delivery' => ['type' => 'delivery', 'zoneId' => 'z3', 'address' => 'Isinya']]);
        $res->assertJson(['deliveryFee' => 0, 'total' => 56000]);
    }

    public function test_delivery_needs_a_zone(): void
    {
        $hammer = $this->product('claw-hammer-16-oz');
        $this->order('mjengo', [['productId' => $hammer->id, 'qty' => 1]], ['delivery' => ['type' => 'delivery', 'address' => 'Somewhere']])
            ->assertStatus(422)->assertJson(['field' => 'zoneId']);
    }

    public function test_quote_delivery_is_agreed_later(): void
    {
        $bar = $this->product('d12-deformed-bar-y12', 'baraka-builders');
        $res = $this->order('baraka-builders', [['productId' => $bar->id, 'qty' => 10]], ['delivery' => ['type' => 'delivery', 'address' => 'Lanet']]);
        $res->assertJson(['deliveryFee' => 0, 'delivery' => ['zone' => 'Agreed with the shop']]);
    }

    public function test_cannot_buy_more_than_in_stock(): void
    {
        $drill = $this->product('cordless-drill-18-v'); // 4 in stock
        $res = $this->order('mjengo', [['productId' => $drill->id, 'qty' => 5]]);
        $res->assertStatus(409);
        $this->assertStringContainsString('Only 4 left', $res->json('message'));
        $this->order('mjengo', [['productId' => $drill->id, 'qty' => 4]])->assertStatus(201);
        $res = $this->order('mjengo', [['productId' => $drill->id, 'qty' => 1]]);
        $res->assertStatus(409);
        $this->assertStringContainsString('sold out', $res->json('message'));
    }

    public function test_paid_order_sends_money_to_the_shop(): void
    {
        $hammer = $this->product('claw-hammer-16-oz');
        $order = $this->order('mjengo', [['productId' => $hammer->id, 'qty' => 1]])->json();
        $this->getJson("/api/storefront/mjengo/orders/{$order['id']}/payment");
        $payout = Payout::where('sale_number', $this->saleNumber($order['id']))->first();
        $this->assertSame(['paid', 650, 'Till 5123456'], [$payout->status, (int) $payout->amount, $payout->destination]);
    }

    public function test_pay_shop_directly_then_shop_confirms(): void
    {
        $owner = $this->owner();
        $hammer = $this->product('claw-hammer-16-oz');
        $this->order('mjengo', [['productId' => $hammer->id, 'qty' => 1]], ['paymentMode' => 'direct', 'mpesaCode' => 'nope'])
            ->assertStatus(422)->assertJson(['field' => 'mpesaCode']);

        $res = $this->order('mjengo', [['productId' => $hammer->id, 'qty' => 1]], ['paymentMode' => 'direct', 'mpesaCode' => 'tjk4h2pq9x']);
        $res->assertStatus(201)->assertJson(['status' => 'awaiting_confirmation', 'payment' => null, 'mpesaRef' => 'TJK4H2PQ9X']);

        $confirmed = $this->patchJson("/api/vendor/stores/{$owner['storeId']}/sales/{$res->json('id')}", ['status' => 'new'], $owner['headers']);
        $confirmed->assertJson(['status' => 'new']);
        $this->assertNotEmpty($confirmed->json('paidAt'));
    }

    public function test_rejected_direct_payment_returns_stock(): void
    {
        $owner = $this->owner();
        $hammer = $this->product('claw-hammer-16-oz');
        $before = $hammer->stock;
        $res = $this->order('mjengo', [['productId' => $hammer->id, 'qty' => 3]], ['paymentMode' => 'direct', 'mpesaCode' => 'TJK4H2PQ9X']);
        $this->patchJson("/api/vendor/stores/{$owner['storeId']}/sales/{$res->json('id')}", ['status' => 'cancelled'], $owner['headers'])->assertOk();
        $this->assertSame($before, $hammer->fresh()->stock);
    }

    public function test_simulated_phone_prompt_approve_and_decline(): void
    {
        config(['topline.simulation_approval' => 'prompt']);
        $hammer = $this->product('claw-hammer-16-oz');
        $order = $this->order('mjengo', [['productId' => $hammer->id, 'qty' => 1]])->json();
        $ref = $order['payment']['reference'];
        $this->assertSame('pending', $this->getJson("/api/storefront/mjengo/orders/{$order['id']}/payment")->json('status'));
        $this->postJson("/api/simulator/payments/{$ref}", ['action' => 'approve', 'pin' => '12'])->assertStatus(422);
        $this->assertSame('paid', $this->postJson("/api/simulator/payments/{$ref}", ['action' => 'approve', 'pin' => '1234'])->json('status'));

        $order2 = $this->order('mjengo', [['productId' => $hammer->id, 'qty' => 1]])->json();
        $this->assertSame('failed', $this->postJson("/api/simulator/payments/{$order2['payment']['reference']}", ['action' => 'decline'])->json('status'));
        $this->assertSame('cancelled', $this->getJson("/api/storefront/mjengo/orders/{$order2['id']}/payment")->json('order.status'));
    }

    public function test_owner_sets_payout_and_delivery(): void
    {
        $owner = $this->owner();
        $base = "/api/vendor/stores/{$owner['storeId']}";
        $this->patchJson($base, ['payments' => ['method' => 'paybill', 'number' => '247247', 'account' => '']], $owner['headers'])
            ->assertStatus(422)->assertJson(['field' => 'payoutAccount']);
        $res = $this->patchJson($base, [
            'payments' => ['method' => 'pochi', 'number' => '0712 345 678'],
            'delivery' => ['enabled' => true, 'mode' => 'zones', 'zones' => [['name' => 'Town', 'fee' => 250], ['name' => '', 'fee' => 9]], 'freeOver' => 30000],
        ], $owner['headers']);
        $res->assertOk()->assertJson(['payments' => ['method' => 'pochi', 'number' => '254712345678'], 'delivery' => ['freeOver' => 30000]]);
        $this->assertSame(['Town'], array_column($res->json('delivery.zones'), 'name'));
    }

    public function test_shop_sees_its_payouts(): void
    {
        $owner = $this->owner();
        $rows = $this->getJson("/api/vendor/stores/{$owner['storeId']}/payouts", $owner['headers'])->json();
        $this->assertNotEmpty($rows);
        $this->assertSame('paid', $rows[0]['status']);
    }

    public function test_abandoned_prompt_is_settled_by_the_sweep(): void
    {
        config(['topline.simulation_approval' => 'prompt']);
        $hammer = $this->product('claw-hammer-16-oz');
        $before = $hammer->stock;
        $order = $this->order('mjengo', [['productId' => $hammer->id, 'qty' => 2]])->json();
        $this->assertSame($before - 2, $hammer->fresh()->stock);

        $this->assertSame(0, PaymentRecords::sweepPending()); // too new to touch
        $payment = Payment::find($order['payment']['reference']);
        $payment->created_at = now()->subMinutes(config('topline.payment_timeout_minutes') + 1);
        $payment->save();
        $this->assertSame(1, PaymentRecords::sweepPending());
        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame('cancelled', Sale::find($this->saleNumber($order['id']))->status);
        $this->assertSame($before, $hammer->fresh()->stock);
    }

    public function test_sweep_completes_orders_paid_while_nobody_watched(): void
    {
        config(['topline.simulation_approval' => 'prompt']);
        $hammer = $this->product('claw-hammer-16-oz');
        $order = $this->order('mjengo', [['productId' => $hammer->id, 'qty' => 1]])->json();
        $payment = Payment::find($order['payment']['reference']);
        $payment->simulated_answer = 'approve'; // approved on the phone, then the page was closed
        $payment->created_at = now()->subMinutes(3);
        $payment->save();
        $this->assertSame(1, PaymentRecords::sweepPending());
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('paid', Payout::where('sale_number', $this->saleNumber($order['id']))->first()->status);
    }

    public function test_cron_endpoint_runs_the_sweeps(): void
    {
        config(['topline.cron_secret' => 'secret-123']);
        $this->getJson('/api/cron/run?key=wrong')->assertStatus(404); // hidden without the key
        $this->getJson('/api/cron/run?key=secret-123')->assertOk();
    }
}
