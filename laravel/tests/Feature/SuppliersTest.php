<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Connect to suppliers: landing profiles, approvals, the supplier portal and orders from shops. */
class SuppliersTest extends TestCase
{
    private const CEMENT_LOGIN = '0700 111 222';

    private function supplierLogin(string $phone = self::CEMENT_LOGIN): array
    {
        return $this->login($phone);
    }

    private function profile(array $over = []): array
    {
        return [
            'name' => 'Lake Basin Paints', 'description' => 'Emulsion and gloss paints made in Kisumu.', 'categories' => 'Paint, Paint, Primers',
            'location' => 'Kisumu', 'phone' => '0711 000 999', 'email' => 'Sales@LakeBasin.example.com', 'website' => 'https://lakebasin.example.com',
            'minOrder' => '20 tins', 'deliveryInfo' => 'Weekly lorry to Nairobi.', 'paymentOptions' => ['bank', 'mpesa'],
            'paymentInstructions' => 'Paybill 123456, account = your shop name.', ...$over,
        ];
    }

    private function cement(): Supplier
    {
        return Supplier::where('slug', 'rift-valley-cement')->firstOrFail();
    }

    public function test_landing_shows_only_listed_profiles_without_private_details(): void
    {
        $list = $this->getJson('/api/suppliers')->assertOk()->json();
        $this->assertSame(['Pwani Steel Mills', 'Rift Valley Cement Co.'], array_column($list, 'name')); // the pending one is hidden
        $cement = collect($list)->firstWhere('slug', 'rift-valley-cement');
        $this->assertSame(3, $cement['connectedShops']);
        $this->assertSame(['Cement', 'Ready-mix'], $cement['categories']);
        foreach (['phone', 'email', 'paymentInstructions', 'paymentOptions', 'products', 'draft'] as $private) {
            $this->assertArrayNotHasKey($private, $cement);
        }
    }

    public function test_admin_adds_a_supplier_who_must_choose_a_password_and_complete_the_profile(): void
    {
        $admin = $this->admin();
        $this->postJson('/api/admin/suppliers', ['name' => 'Lake Basin Paints', 'contactName' => 'Achieng', 'phone' => '0712 345 678'], $admin['headers'])
            ->assertStatus(422)->assertJson(['field' => 'phone']); // already a shop owner's login
        $res = $this->postJson('/api/admin/suppliers', ['name' => 'Lake Basin Paints', 'contactName' => 'Achieng', 'phone' => '0711 000 999'], $admin['headers']);
        $res->assertStatus(201)->assertJson(['supplier' => ['status' => 'pending', 'slug' => 'lake-basin-paints']]);
        $temp = $res->json('temporaryPassword');
        $this->assertSame(10, strlen($temp));

        $login = $this->login('0711 000 999', $temp);
        $this->assertSame('supplier', $login['user']['role']);
        $this->getJson('/api/supplier/me', $login['headers'])->assertStatus(403)->assertJson(['field' => 'newPassword']);
        $changed = $this->postJson('/api/auth/password', ['newPassword' => 'paints-2026'], $login['headers'])->assertOk();
        $h = $this->auth($changed->json('token'));

        $this->putJson('/api/supplier/profile', $this->profile(['paymentOptions' => []]), $h)->assertStatus(422)->assertJson(['field' => 'paymentOptions']);
        $this->putJson('/api/supplier/profile', $this->profile(['website' => 'javascript:alert(1)']), $h)->assertStatus(422)->assertJson(['field' => 'website']);
        $this->putJson('/api/supplier/profile', $this->profile(['logo' => 'javascript:alert(1)']), $h)->assertStatus(422)->assertJson(['field' => 'logo']);
        $me = $this->putJson('/api/supplier/profile', $this->profile(), $h)->assertOk()->json();
        // Not approved yet: edits go straight into the profile, which waits for review.
        $this->assertSame(['reviewStatus' => 'pending', 'draft' => null], ['reviewStatus' => $me['reviewStatus'], 'draft' => $me['draft']]);
        $this->assertSame(['Paint', 'Primers'], $me['live']['categories']);
        $this->assertSame('sales@lakebasin.example.com', $me['live']['email']);
        $this->assertNotContains('Lake Basin Paints', array_column($this->getJson('/api/suppliers')->json(), 'name'));

        $id = $res->json('supplier.id');
        $this->postJson("/api/admin/suppliers/{$id}/review", ['action' => 'approve'], $admin['headers'])->assertOk()->assertJson(['status' => 'approved']);
        $this->assertContains('Lake Basin Paints', array_column($this->getJson('/api/suppliers')->json(), 'name'));
    }

    public function test_changes_to_an_approved_profile_wait_for_approval(): void
    {
        $s = $this->supplierLogin();
        $admin = $this->admin();
        $cement = $this->cement();
        $me = $this->putJson('/api/supplier/profile', $this->profile(['name' => 'Rift Valley Cement Co.', 'description' => 'NEW description']), $s['headers'])->json();
        $this->assertSame('NEW description', $me['draft']['description']);
        $this->assertNotSame('NEW description', $me['live']['description']);
        $public = collect($this->getJson('/api/suppliers')->json())->firstWhere('slug', 'rift-valley-cement');
        $this->assertNotSame('NEW description', $public['description']); // shops still see the approved one

        $this->postJson("/api/admin/suppliers/{$cement->id}/review", ['action' => 'reject', 'note' => 'Add your KEBS number.'], $admin['headers'])
            ->assertOk()->assertJson(['reviewStatus' => 'rejected', 'reviewNote' => 'Add your KEBS number.']);
        $this->putJson('/api/supplier/profile', $this->profile(['name' => 'Rift Valley Cement Co.', 'description' => 'KEBS 1234']), $s['headers']);
        $this->postJson("/api/admin/suppliers/{$cement->id}/review", ['action' => 'approve'], $admin['headers'])->assertOk()->assertJson(['draft' => null]);
        $public = collect($this->getJson('/api/suppliers')->json())->firstWhere('slug', 'rift-valley-cement');
        $this->assertSame('KEBS 1234', $public['description']);
    }

    public function test_products_need_approval_and_shops_see_only_approved_versions(): void
    {
        $s = $this->supplierLogin();
        $admin = $this->admin();
        $owner = $this->owner();
        $base = "/api/vendor/stores/{$owner['storeId']}/suppliers/rift-valley-cement";

        $new = $this->postJson('/api/supplier/products', ['name' => 'Waterproof cement', 'unit' => 'bag', 'price' => 950, 'minQty' => 50], $s['headers'])
            ->assertStatus(201)->assertJson(['status' => 'pending'])->json();
        $this->assertNotContains('Waterproof cement', array_column($this->getJson($base, $owner['headers'])->json('products'), 'name'));
        $this->postJson("/api/admin/supplier-products/{$new['id']}/review", ['action' => 'approve'], $admin['headers'])->assertOk();
        $this->assertContains('Waterproof cement', array_column($this->getJson($base, $owner['headers'])->json('products'), 'name'));

        $edit = $this->putJson("/api/supplier/products/{$new['id']}", ['name' => 'Waterproof cement', 'unit' => 'bag', 'price' => 990, 'minQty' => 50], $s['headers'])->json();
        $this->assertSame([950, 990], [$edit['price'], $edit['pendingChange']['price']]);
        $shopView = collect($this->getJson($base, $owner['headers'])->json('products'))->firstWhere('id', $new['id']);
        $this->assertSame(950, $shopView['price']);
        $this->assertArrayNotHasKey('pendingChange', $shopView);
        $this->postJson("/api/admin/supplier-products/{$new['id']}/review", ['action' => 'reject', 'note' => 'Too high'], $admin['headers'])
            ->assertJson(['price' => 950, 'pendingChange' => null, 'reviewNote' => 'Change not approved: Too high']);

        $this->getJson('/api/admin/suppliers', $admin['headers'])->assertOk()->assertJson(['pendingReviews' => 3]); // BuildPro profile + its product + Y12 price
    }

    public function test_shop_orders_from_a_supplier_and_the_supplier_follows_up(): void
    {
        $owner = $this->owner();
        $cement = $this->cement();
        $c325 = SupplierProduct::where('supplier_id', $cement->id)->where('name', 'like', 'Cement 32.5N%')->first();
        $url = "/api/vendor/stores/{$owner['storeId']}/suppliers/rift-valley-cement/orders";
        $order = ['items' => [['productId' => $c325->id, 'qty' => 300], ['name' => 'Cement 42.5 in 25 kg bags', 'unit' => 'bag', 'qty' => 40]],
            'contactName' => 'Peter Kamau', 'contactPhone' => '0712 345 678', 'deliveryLocation' => 'Kitengela, Namanga Road', 'paymentOption' => 'supplier_credit'];

        $this->postJson($url, [...$order, 'items' => [['productId' => $c325->id, 'qty' => 20]]], $owner['headers'])->assertStatus(422)->assertJson(['field' => 'items']);
        $this->postJson($url, [...$order, 'paymentOption' => 'on_delivery'], $owner['headers'])->assertStatus(422)->assertJson(['field' => 'paymentOption']);
        $placed = $this->postJson($url, $order, $owner['headers'])->assertStatus(201)->json();
        $this->assertSame(['new', 207000, 'Supplier credit / LPO (pay in 30–90 days)'], [$placed['status'], $placed['estimatedTotal'], $placed['paymentOption']['label']]);
        $this->assertNull($placed['items'][1]['price']);
        $this->assertSame('Rift Valley Cement Co.', $placed['supplier']['name']);

        $s = $this->supplierLogin();
        $incoming = collect($this->getJson('/api/supplier/orders', $s['headers'])->json())->firstWhere('id', $placed['id']);
        $this->assertSame(['Mjengo Hardware', '0712 345 678'], [$incoming['store']['name'], $incoming['contactPhone']]);
        $steel = $this->supplierLogin('0700 111 333');
        $this->assertNotContains($placed['id'], array_column($this->getJson('/api/supplier/orders', $steel['headers'])->json(), 'id'));
        $this->patchJson("/api/supplier/orders/{$placed['id']}", ['status' => 'completed'], $steel['headers'])->assertStatus(404);

        $this->patchJson("/api/supplier/orders/{$placed['id']}", ['status' => 'cancelled'], $s['headers'])->assertStatus(422);
        $this->patchJson("/api/supplier/orders/{$placed['id']}", ['status' => 'contacted'], $s['headers'])->assertOk()->assertJson(['status' => 'contacted']);
        $this->patchJson("/api/vendor/stores/{$owner['storeId']}/supplier-orders/{$placed['id']}", ['status' => 'cancelled'], $owner['headers'])
            ->assertStatus(422); // the supplier is already on it
        $mine = $this->getJson("/api/vendor/stores/{$owner['storeId']}/supplier-orders", $owner['headers'])->json();
        $this->assertSame('contacted', collect($mine)->firstWhere('id', $placed['id'])['status']);
        $this->assertArrayNotHasKey('store', $mine[0]);
    }

    public function test_who_can_reach_what(): void
    {
        $cashier = $this->cashier();
        $s = $this->supplierLogin();
        $owner = $this->owner();
        $this->getJson("/api/vendor/stores/{$cashier['storeId']}/suppliers", $cashier['headers'])->assertStatus(403);
        $this->getJson("/api/vendor/stores/{$owner['storeId']}/suppliers/buildpro-trading", $owner['headers'])->assertStatus(404); // not approved
        $this->getJson("/api/vendor/stores/{$owner['storeId']}/dashboard", $s['headers'])->assertStatus(404); // suppliers aren't shop staff
        $this->getJson('/api/admin/suppliers', $s['headers'])->assertStatus(403);
        $this->getJson('/api/supplier/me', $owner['headers'])->assertStatus(403);
        $this->getJson('/api/supplier/me')->assertStatus(401);

        $other = SupplierProduct::whereHas('supplier', fn ($q) => $q->where('slug', 'pwani-steel-mills'))->first();
        $this->putJson("/api/supplier/products/{$other->id}", ['name' => 'Hacked', 'price' => 1], $s['headers'])->assertStatus(404);
        $this->deleteJson("/api/supplier/products/{$other->id}", [], $s['headers'])->assertStatus(404);

        $png = UploadedFile::fake()->createWithContent('logo.png', "\x89PNG\r\n\x1a\n".str_repeat("\0", 64));
        $this->post('/api/uploads', ['file' => $png], [...$s['headers'], 'Accept' => 'application/json'])->assertStatus(201);
    }

    public function test_hidden_supplier_disappears_and_takes_no_orders(): void
    {
        $admin = $this->admin();
        $owner = $this->owner();
        $cement = $this->cement();
        $this->patchJson("/api/admin/suppliers/{$cement->id}", ['listed' => false], $admin['headers'])->assertOk()->assertJson(['status' => 'hidden']);
        $this->assertNotContains('rift-valley-cement', array_column($this->getJson('/api/suppliers')->json(), 'slug'));
        $this->postJson("/api/vendor/stores/{$owner['storeId']}/suppliers/rift-valley-cement/orders", ['items' => [['name' => 'x', 'qty' => 1]]], $owner['headers'])
            ->assertStatus(404);
        $this->assertCount(3, $this->getJson('/api/supplier/orders', $this->supplierLogin()['headers'])->json()); // their history stays
    }

    public function test_admin_can_reset_a_supplier_password(): void
    {
        $admin = $this->admin();
        $res = $this->postJson("/api/admin/suppliers/{$this->cement()->id}/reset-password", [], $admin['headers'])->assertOk();
        $this->postJson('/api/auth/login', ['login' => self::CEMENT_LOGIN, 'password' => self::PASSWORD])->assertStatus(401);
        $this->assertTrue($this->login(self::CEMENT_LOGIN, $res->json('temporaryPassword'))['user']['mustChangePassword']);
    }
}
