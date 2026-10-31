<?php

namespace Tests\Feature;

use App\Models\Customer;
use Tests\TestCase;

/** The shop owner's mini admin: products with measurements and photos, sales, credit, orders, activity. */
class ShopAdminTest extends TestCase
{
    public function test_create_edit_delete_product(): void
    {
        $o = $this->owner();
        $base = "/api/vendor/stores/{$o['storeId']}";
        $res = $this->postJson("{$base}/products", ['name' => 'Binding wire 1kg', 'sellPrice' => 350, 'buyPrice' => 260, 'stock' => 40, 'categoryId' => 'steel'], $o['headers']);
        $res->assertStatus(201)->assertJson(['slug' => 'binding-wire-1kg', 'stock' => 40]);
        $pid = $res->json('id');

        $this->putJson("{$base}/products/{$pid}", ['name' => 'Binding wire 1 kg roll', 'sellPrice' => 380, 'stock' => 35], $o['headers'])
            ->assertJson(['sellPrice' => 380, 'stock' => 35]);
        $this->assertSame(40, $this->postJson("{$base}/products/{$pid}/stock", ['delta' => 5], $o['headers'])->json('stock'));
        $this->deleteJson("{$base}/products/{$pid}", [], $o['headers'])->assertStatus(204);
    }

    public function test_product_needs_a_price(): void
    {
        $o = $this->owner();
        $this->postJson("/api/vendor/stores/{$o['storeId']}/products", ['name' => 'Nails'], $o['headers'])
            ->assertStatus(422)->assertJson(['field' => 'sellPrice']);
    }

    public function test_product_measurements_photos_and_activity(): void
    {
        $o = $this->owner();
        $base = "/api/vendor/stores/{$o['storeId']}/products";
        $res = $this->postJson($base, [
            'name' => 'Deformed bar Y16', 'categoryId' => 'steel', 'unit' => 'length (12 m)', 'sellPrice' => 1650, 'stock' => 120,
            'size' => '16 mm × 12 m', 'description' => 'High-tensile bar for columns and beams.',
            'specs' => [['label' => 'Grade', 'value' => 'Y16'], ['label' => 'Weight', 'value' => '18.9 kg'], ['label' => '', 'value' => '']],
            'image' => 'rebar', 'gallery' => ['https://api.example/api/uploads/a.jpg', 'steel'],
        ], $o['headers']);
        $res->assertStatus(201);
        $p = $res->json();
        $this->assertSame('16 mm × 12 m', $p['size']);
        $this->assertSame([['label' => 'Grade', 'value' => 'Y16'], ['label' => 'Weight', 'value' => '18.9 kg']], $p['specs']);
        $this->assertSame(['https://api.example/api/uploads/a.jpg', 'steel'], $p['gallery']);

        $this->putJson("{$base}/{$p['id']}", [...$p, 'specs' => [['label' => 'Grade', 'value' => '']]], $o['headers'])
            ->assertStatus(422)->assertJson(['field' => 'specs']);
        $this->putJson("{$base}/{$p['id']}", [...$p, 'gallery' => ['a', 'b', 'c', 'd']], $o['headers'])
            ->assertStatus(422)->assertJson(['field' => 'gallery']);
        $this->putJson("{$base}/{$p['id']}", [...$p, 'stock' => 100], $o['headers'])->assertOk();

        $public = $this->getJson("/api/storefront/mjengo/products/{$p['slug']}")->json();
        $this->assertSame('16 mm × 12 m', $public['product']['size'] ?? $public['size']);

        $summaries = array_column($this->getJson("/api/vendor/stores/{$o['storeId']}/activity", $o['headers'])->json(), 'summary');
        $this->assertNotEmpty(array_filter($summaries, fn ($s) => str_contains($s, 'Deformed bar Y16')));
    }

    public function test_credit_sale_respects_limit(): void
    {
        $o = $this->owner();
        $base = "/api/vendor/stores/{$o['storeId']}";
        $products = $this->getJson("{$base}/products", $o['headers'])->json('products');
        $drill = collect($products)->firstWhere('slug', 'cordless-drill-18-v');
        $mutua = Customer::where('name', 'Mutua Welding')->first(); // owes 58,200 of 60,000

        $this->postJson("{$base}/sales", ['items' => [['productId' => $drill['id'], 'qty' => 1]], 'method' => 'credit', 'customerId' => $mutua->id], $o['headers'])
            ->assertStatus(422)->assertJson(['field' => 'customer']);

        $sunrise = Customer::where('name', 'Sunrise Apartments site')->first();
        $this->postJson("{$base}/sales", ['items' => [['productId' => $drill['id'], 'qty' => 2]], 'method' => 'credit', 'customerId' => $sunrise->id], $o['headers'])
            ->assertStatus(201);
        $sunrise->refresh();
        $this->assertSame(19600, (int) $sunrise->balance);
        $this->assertNotNull($sunrise->due_at);
    }

    public function test_customer_payment_reduces_balance(): void
    {
        $o = $this->owner();
        $jane = Customer::where('name', 'Jane Njeri (fundi)')->first();
        $this->postJson("/api/vendor/stores/{$o['storeId']}/customers/{$jane->id}/payments", ['amount' => 12800], $o['headers'])
            ->assertJson(['balance' => 0, 'dueAt' => null]);
    }

    public function test_online_order_status_flow(): void
    {
        $o = $this->owner();
        $base = "/api/vendor/stores/{$o['storeId']}";
        $new = collect($this->getJson("{$base}/sales?channel=online", $o['headers'])->json())->firstWhere('status', 'new');
        $this->patchJson("{$base}/sales/{$new['id']}", ['status' => 'completed'], $o['headers'])->assertStatus(422);
        $this->patchJson("{$base}/sales/{$new['id']}", ['status' => 'confirmed'], $o['headers'])->assertJson(['status' => 'confirmed']);
    }

    public function test_dashboard_shape(): void
    {
        $o = $this->owner();
        $d = $this->getJson("/api/vendor/stores/{$o['storeId']}/dashboard", $o['headers'])->json();
        $this->assertCount(14, $d['byDay']);
        foreach (['revenue7', 'profit7', 'owedToYou', 'lowStock', 'topProducts', 'recent'] as $key) {
            $this->assertArrayHasKey($key, $d);
        }
        $this->assertSame(2, $d['overdueCustomers']);
    }
}
