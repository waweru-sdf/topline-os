<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Owners reach only their own store; Topline admins reach every store and the admin area. */
class AccessTest extends TestCase
{
    public function test_owner_sees_own_store_with_costs(): void
    {
        $owner = $this->owner();
        $res = $this->getJson("/api/vendor/stores/{$owner['storeId']}/products", $owner['headers']);
        $res->assertOk();
        $this->assertArrayHasKey('buyPrice', $res->json('products.0'));
    }

    public function test_owner_cannot_open_another_store(): void
    {
        $other = $this->store('coast-steel');
        $this->getJson("/api/vendor/stores/{$other->id}/dashboard", $this->owner()['headers'])->assertStatus(404);
    }

    public function test_owner_cannot_use_platform_admin(): void
    {
        $this->getJson('/api/admin/stores', $this->owner()['headers'])->assertStatus(403);
    }

    public function test_admin_can_open_any_store_admin(): void
    {
        $admin = $this->admin();
        $id = $this->store()->id;
        $res = $this->getJson("/api/vendor/stores/{$id}/dashboard", $admin['headers']);
        $res->assertOk();
        $this->assertGreaterThan(0, $res->json('revenue7'));
        $this->assertSame('platform_admin', $this->getJson("/api/vendor/stores/{$id}", $admin['headers'])->json('myRole'));
    }

    public function test_admin_lists_every_store(): void
    {
        $admin = $this->admin();
        $this->assertCount(7, $this->getJson('/api/vendor/stores', $admin['headers'])->json());
        $this->assertSame(7, $this->getJson('/api/admin/overview', $admin['headers'])->json('stores.total'));
    }

    public function test_cashier_sells_but_never_sees_buying_prices(): void
    {
        $cashier = $this->cashier();
        $base = "/api/vendor/stores/{$cashier['storeId']}";
        $products = $this->getJson("{$base}/products", $cashier['headers'])->json('products');
        $this->assertArrayNotHasKey('buyPrice', $products[0]);
        $this->getJson("{$base}/dashboard", $cashier['headers'])->assertStatus(403);
        $this->patchJson($base, ['name' => 'Hacked'], $cashier['headers'])->assertStatus(403);
        $sale = $this->postJson("{$base}/sales", ['items' => [['productId' => $products[0]['id'], 'qty' => 1]], 'method' => 'cash'], $cashier['headers']);
        $sale->assertStatus(201);
        $this->assertArrayNotHasKey('cost', $sale->json('items.0'));
    }

    public function test_owner_cannot_change_plan_or_status_through_settings(): void
    {
        $owner = $this->owner();
        $res = $this->patchJson("/api/vendor/stores/{$owner['storeId']}", ['name' => 'Mjengo Hardware Ltd', 'planId' => 'pro', 'status' => 'active'], $owner['headers']);
        $res->assertOk()->assertJson(['name' => 'Mjengo Hardware Ltd', 'planId' => 'business']);
    }

    public function test_cashier_cannot_see_payouts_or_activity(): void
    {
        $cashier = $this->cashier();
        $this->getJson("/api/vendor/stores/{$cashier['storeId']}/payouts", $cashier['headers'])->assertStatus(403);
        $this->getJson("/api/vendor/stores/{$cashier['storeId']}/activity", $cashier['headers'])->assertStatus(403);
    }
}
