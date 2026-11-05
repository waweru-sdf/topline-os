<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

/** What the plans promise: staff logins, branches, own domain, supplier balances, quotations. Mjengo is on Business. */
class PlanFeaturesTest extends TestCase
{
    private function base(array $o): string
    {
        return "/api/vendor/stores/{$o['storeId']}";
    }

    public function test_owner_adds_staff_who_log_in_with_a_temporary_password(): void
    {
        $o = $this->owner();
        $base = $this->base($o);
        $this->postJson("{$base}/staff", ['name' => 'Esther', 'phone' => '0712 345 678', 'role' => 'cashier'], $o['headers'])
            ->assertStatus(422)->assertJson(['field' => 'phone']); // the owner's own number
        $this->postJson("{$base}/staff", ['name' => 'Esther', 'phone' => '0733 100 200', 'role' => 'owner'], $o['headers'])->assertStatus(422)->assertJson(['field' => 'role']);
        $added = $this->postJson("{$base}/staff", ['name' => 'Esther', 'phone' => '0733 100 200', 'role' => 'cashier'], $o['headers'])->assertStatus(201)->json();
        $this->assertSame(['cashier', null], [$added['member']['role'], $added['member']['branchId']]);

        $cashier = $this->login('0733 100 200', $added['temporaryPassword']);
        $this->assertSame([$o['storeId'], 'cashier', true], [$cashier['user']['storeId'], $cashier['user']['storeRole'], $cashier['user']['mustChangePassword']]);
        $this->postJson('/api/auth/password', ['currentPassword' => $added['temporaryPassword'], 'newPassword' => 'esther-2026'], $cashier['headers'])->assertOk();
        $cashier = $this->login('0733 100 200', 'esther-2026');
        $this->getJson("{$base}/staff", $cashier['headers'])->assertStatus(403);
        $this->assertArrayNotHasKey('buyPrice', $this->getJson("{$base}/products", $cashier['headers'])->json('products.0'));

        $manager = $this->postJson("{$base}/staff", ['name' => 'Otieno', 'phone' => '0733 100 300', 'role' => 'manager'], $o['headers'])->assertStatus(201)->json();
        $m = $this->login('0733 100 300', $manager['temporaryPassword']);
        $this->getJson("{$base}/staff", $m['headers'])->assertStatus(403); // only owners manage staff (password change needed first anyway)

        $this->patchJson("{$base}/staff/{$added['member']['id']}", ['role' => 'manager'], $o['headers'])->assertOk()->assertJson(['role' => 'manager']);
        $ownerMember = collect($this->getJson("{$base}/staff", $o['headers'])->json())->firstWhere('role', 'owner');
        $this->deleteJson("{$base}/staff/{$ownerMember['id']}", [], $o['headers'])->assertStatus(403);

        $this->deleteJson("{$base}/staff/{$added['member']['id']}", [], $o['headers'])->assertNoContent();
        $this->getJson('/api/auth/me', $cashier['headers'])->assertStatus(401); // logged out at once
        $this->postJson('/api/auth/login', ['login' => '0733 100 200', 'password' => 'esther-2026'])->assertStatus(403);
        $again = $this->postJson("{$base}/staff", ['name' => 'Esther W', 'phone' => '0733 100 200', 'role' => 'cashier'], $o['headers'])->assertStatus(201)->json();
        $this->login('0733 100 200', $again['temporaryPassword']); // a former staff member can come back
    }

    public function test_staff_limit_follows_the_plan(): void
    {
        $owner = $this->login('0733 902 117'); // Kilimani Paints, Starter: 2 staff logins
        $base = $this->base($owner);
        foreach (['0733 200 001', '0733 200 002'] as $phone) {
            $this->postJson("{$base}/staff", ['name' => 'Staff', 'phone' => $phone, 'role' => 'cashier'], $owner['headers'])->assertStatus(201);
        }
        $this->postJson("{$base}/staff", ['name' => 'Staff', 'phone' => '0733 200 003', 'role' => 'cashier'], $owner['headers'])
            ->assertStatus(422)->assertJson(['message' => 'The Starter plan includes 2 staff logins. Change plan under Plan & billing to add more.']);
    }

    public function test_branches_have_their_own_stock_sales_and_staff(): void
    {
        $o = $this->owner();
        $base = $this->base($o);
        $hammer = $this->product('claw-hammer-16-oz');
        $mainStock = $hammer->stock;
        $store = $this->getJson($base, $o['headers'])->json();
        $this->assertSame([1, 2, false], [count($store['branches']), $store['limits']['branches'], $store['limits']['customDomain']]);
        $main = $store['branches'][0];
        $this->assertTrue($main['isMain']);

        $kit = $this->postJson("{$base}/branches", ['name' => 'Kitengela', 'location' => 'Namanga Rd'], $o['headers'])->assertStatus(201)->json();
        $this->postJson("{$base}/branches", ['name' => 'Rongai'], $o['headers'])->assertStatus(422); // Business: 2 branches
        $this->postJson("{$base}/branches", ['name' => 'main shop'], $o['headers'])->assertStatus(422); // same name

        // New branch starts empty; stock is moved or counted in per branch.
        $at = fn ($branchId) => collect($this->getJson("{$base}/products?branchId={$branchId}", $o['headers'])->json('products'))->firstWhere('id', $hammer->id)['stock'];
        $this->assertSame([$mainStock, 0], [$at($main['id']), $at($kit['id'])]);
        $this->postJson("{$base}/products/{$hammer->id}/transfer", ['fromBranchId' => $main['id'], 'toBranchId' => $kit['id'], 'qty' => 5], $o['headers'])->assertOk();
        $this->postJson("{$base}/products/{$hammer->id}/stock", ['delta' => 2, 'branchId' => $kit['id']], $o['headers'])->assertOk()->assertJson(['stock' => 7]);
        $this->assertSame([$mainStock - 5, 7], [$at($main['id']), $at($kit['id'])]);
        $this->assertSame(7, $this->getJson("{$base}/products", $o['headers'])->json("stockByBranch.{$kit['id']}.{$hammer->id}"));

        // A cashier tied to Kitengela sells from Kitengela even if they ask for the main branch.
        $added = $this->postJson("{$base}/staff", ['name' => 'Wanjiru', 'phone' => '0733 300 400', 'role' => 'cashier', 'branchId' => $kit['id']], $o['headers'])->json();
        $c = $this->login('0733 300 400', $added['temporaryPassword']);
        $this->postJson('/api/auth/password', ['currentPassword' => $added['temporaryPassword'], 'newPassword' => 'wanjiru-2026'], $c['headers'])->assertOk();
        $c = $this->login('0733 300 400', 'wanjiru-2026');
        $this->assertSame($kit['id'], $c['user']['branchId']);
        $sale = $this->postJson("{$base}/sales", ['items' => [['productId' => $hammer->id, 'qty' => 3]], 'method' => 'cash', 'branchId' => $main['id']], $c['headers'])
            ->assertStatus(201)->json();
        $this->assertSame($kit['id'], $sale['branchId']);
        $this->assertSame([$mainStock - 5, 4], [$at($main['id']), $at($kit['id'])]);
        $this->assertSame(4, collect($this->getJson("{$base}/products", $c['headers'])->json('products'))->firstWhere('id', $hammer->id)['stock']);
        $this->postJson("{$base}/products/{$hammer->id}/transfer", ['fromBranchId' => $kit['id'], 'toBranchId' => $main['id'], 'qty' => 1], $c['headers'])->assertStatus(403);

        // Overview per branch; the whole shop by default.
        $kitSales = $this->getJson("{$base}/dashboard?branchId={$kit['id']}", $o['headers'])->json('sales7');
        $allSales = $this->getJson("{$base}/dashboard", $o['headers'])->json('sales7');
        $this->assertSame(1, $kitSales);
        $this->assertGreaterThan(1, $allSales);
        $this->assertCount(1, $this->getJson("{$base}/sales?branchId={$kit['id']}", $o['headers'])->json());

        $this->deleteJson("{$base}/branches/{$kit['id']}", [], $o['headers'])->assertStatus(422); // has sales
        $this->deleteJson("{$base}/branches/{$main['id']}", [], $o['headers'])->assertStatus(422);
        $this->patchJson("{$base}/branches/{$kit['id']}", ['name' => 'Kitengela Town'], $o['headers'])->assertOk()->assertJson(['name' => 'Kitengela Town']);
    }

    public function test_own_domain_on_plans_that_include_it(): void
    {
        $starter = $this->owner(); // Business: no own domain
        $this->putJson($this->base($starter).'/domain', ['domain' => 'mjengo.co.ke'], $starter['headers'])->assertStatus(422)->assertJson(['field' => 'domain']);

        $pro = $this->login('0733 444 555'); // Mwamba, Pro
        $base = $this->base($pro);
        $this->putJson("{$base}/domain", ['domain' => 'baraka.toplinehub.co.ke'], $pro['headers'])->assertStatus(422); // another shop's name
        $this->putJson("{$base}/domain", ['domain' => 'www.toplinehub.co.ke'], $pro['headers'])->assertStatus(422);
        $this->putJson("{$base}/domain", ['domain' => 'a.mwamba.toplinehub.co.ke'], $pro['headers'])->assertStatus(422);
        $this->putJson("{$base}/domain", ['domain' => 'not a domain'], $pro['headers'])->assertStatus(422);
        $this->putJson("{$base}/domain", ['domain' => 'https://www.MwambaHardware.co.ke/'], $pro['headers'])->assertOk()
            ->assertJson(['customDomain' => 'mwambahardware.co.ke', 'domainStatus' => 'pending']);
        $this->getJson('/api/domains/resolve?host=mwambahardware.co.ke')->assertStatus(404); // not connected yet

        $admin = $this->admin();
        $this->assertSame(1, $this->getJson('/api/admin/badges', $admin['headers'])->json('pendingDomains'));
        $mwamba = $this->store('mwamba');
        $this->patchJson("/api/admin/stores/{$mwamba->id}", ['domainStatus' => 'connected'], $admin['headers'])->assertOk()->assertJson(['domainStatus' => 'connected']);
        $this->getJson('/api/domains/resolve?host=mwambahardware.co.ke')->assertOk()->assertExactJson(['slug' => 'mwamba']);
        $this->getJson('/api/domains/resolve?host=www.mwambahardware.co.ke')->assertOk()->assertJson(['slug' => 'mwamba']);
        $this->getJson('/api/domains/resolve?host=elsewhere.co.ke')->assertStatus(404);

        // Or a Topline subdomain named after the shop.
        $this->putJson("{$base}/domain", ['domain' => 'mwamba.toplinehub.co.ke'], $pro['headers'])->assertOk()->assertJson(['customDomain' => 'mwamba.toplinehub.co.ke', 'domainStatus' => 'pending']);
        $this->getJson('/api/domains/resolve?host=mwambahardware.co.ke')->assertStatus(404); // the old one stopped
        $this->getJson('/api/domains/resolve?host=mwamba.toplinehub.co.ke')->assertStatus(404);
        $this->patchJson("/api/admin/stores/{$mwamba->id}", ['domainStatus' => 'connected'], $admin['headers'])->assertOk();
        $this->getJson('/api/domains/resolve?host=mwamba.toplinehub.co.ke')->assertOk()->assertExactJson(['slug' => 'mwamba']);
        $this->getJson('/api/domains/resolve?host=new.toplinehub.co.ke')->assertStatus(404); // a test site stays the main site
        $this->putJson("{$base}/domain", ['domain' => 'mwambahardware.co.ke'], $pro['headers'])->assertOk();
        $this->patchJson("/api/admin/stores/{$mwamba->id}", ['domainStatus' => 'connected'], $admin['headers'])->assertOk();

        $other = $this->login('0722 118 404'); // Baraka, also Pro
        $this->putJson($this->base($other).'/domain', ['domain' => 'mwambahardware.co.ke'], $other['headers'])->assertStatus(422); // taken

        Store::whereKey($mwamba->id)->update(['plan_id' => 'business']); // downgraded: the domain stops working
        \Illuminate\Support\Facades\Cache::flush();
        $this->getJson('/api/domains/resolve?host=mwambahardware.co.ke')->assertStatus(404);
    }

    public function test_supplier_balances(): void
    {
        $o = $this->owner();
        $base = $this->base($o);
        $acc = $this->postJson("{$base}/supplier-accounts", ['name' => 'Kamau Distributors', 'phone' => '0722 555 111'], $o['headers'])->assertStatus(201)->json();
        $this->postJson("{$base}/supplier-accounts", ['name' => 'kamau distributors'], $o['headers'])->assertStatus(422);
        $this->postJson("{$base}/supplier-accounts/{$acc['id']}/entries", ['kind' => 'bill', 'amount' => 80000, 'date' => '2026-10-20', 'dueOn' => '2026-11-20', 'note' => 'Invoice 114'], $o['headers'])
            ->assertStatus(201)->assertJson(['balance' => 80000]);
        $after = $this->postJson("{$base}/supplier-accounts/{$acc['id']}/entries", ['kind' => 'payment', 'amount' => 30000], $o['headers'])->assertStatus(201)->json();
        $this->assertSame([50000, 'payment', 'bill'], [$after['balance'], $after['entries'][0]['kind'], $after['entries'][1]['kind']]);
        $this->postJson("{$base}/supplier-accounts/{$acc['id']}/entries", ['kind' => 'gift', 'amount' => 1], $o['headers'])->assertStatus(422);
        $list = $this->getJson("{$base}/supplier-accounts", $o['headers'])->json();
        $this->assertSame([50000, '2026-11-20'], [$list['totalOwed'], substr($list['accounts'][0]['nextDue'], 0, 10)]);
        $this->assertSame(50000, $this->getJson("{$base}/accounting", $o['headers'])->json('owedToSuppliers'));
        $this->deleteJson("{$base}/supplier-accounts/{$acc['id']}", [], $o['headers'])->assertStatus(422); // balance not settled
        $this->getJson("{$base}/supplier-accounts", $this->cashier()['headers'])->assertStatus(403);
        $this->getJson("{$base}/supplier-accounts/{$acc['id']}", $this->login('0733 444 555')['headers'])->assertStatus(404); // another shop
    }

    public function test_quotations_turn_into_sales(): void
    {
        $o = $this->owner();
        $base = $this->base($o);
        $hammer = $this->product('claw-hammer-16-oz');
        $q = $this->postJson("{$base}/quotes", ['name' => 'Fundi Juma', 'phone' => '0722 999 000', 'items' => [['productId' => $hammer->id, 'qty' => 4]]], $o['headers'])
            ->assertStatus(201)->json();
        $this->assertSame(['shop', 'replied', 4 * $hammer->sell_price], [$q['source'], $q['status'], $q['total']]);

        // A customer's request from the store page, priced at the till.
        $req = $this->postJson('/api/storefront/mjengo/quotes', ['name' => 'Akinyi', 'phone' => '0711 222 333', 'items' => '50 bags cement, 20 Y12'])->assertStatus(201)->json('reference');
        $request = collect($this->getJson("{$base}/quotes", $o['headers'])->json())->firstWhere('reference', $req);
        $priced = $this->postJson("{$base}/quotes", ['quoteId' => $request['id'], 'items' => [['productId' => $hammer->id, 'qty' => 1]]], $o['headers'])->assertStatus(201)->json();
        $this->assertSame([$request['id'], 'customer', 'Akinyi'], [$priced['id'], $priced['source'], $priced['name']]);

        $before = $hammer->stock;
        $sale = $this->postJson("{$base}/sales", ['items' => [['productId' => $hammer->id, 'qty' => 4]], 'method' => 'cash', 'quoteId' => $q['id']], $o['headers'])->assertStatus(201)->json();
        $won = collect($this->getJson("{$base}/quotes", $o['headers'])->json())->firstWhere('id', $q['id']);
        $this->assertSame(['won', $sale['id']], [$won['status'], $won['saleId']]);
        $this->assertSame($before - 4, $hammer->refresh()->stock);
        $this->postJson("{$base}/quotes", ['quoteId' => $q['id'], 'items' => [['productId' => $hammer->id, 'qty' => 1]]], $o['headers'])->assertStatus(422);
    }

    public function test_admin_sets_plan_limits(): void
    {
        $admin = $this->admin();
        $this->putJson('/api/admin/plans/business', ['name' => 'Business', 'priceMonthly' => 3500, 'priceYearly' => 35000,
            'staffLimit' => 3, 'branchLimit' => 3, 'customDomain' => true], $admin['headers'])->assertOk()
            ->assertJson(['limits' => ['products' => 1500, 'staff' => 3, 'branches' => 3, 'customDomain' => true]]);
        $o = $this->owner();
        $this->assertSame(['staff' => 3, 'branches' => 3, 'customDomain' => true],
            array_intersect_key($this->getJson($this->base($o), $o['headers'])->json('limits'), ['staff' => 1, 'branches' => 1, 'customDomain' => 1]));
        $this->assertNotNull(User::where('phone', '254712345678')->first());
    }
}
