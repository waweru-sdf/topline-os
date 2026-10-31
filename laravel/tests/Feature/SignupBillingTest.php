<?php

namespace Tests\Feature;

use App\Services\Billing;
use Tests\TestCase;

/** Paying for a plan, opening a store with it, renewals, and the billing sweep. */
class SignupBillingTest extends TestCase
{
    private function signup(string $ref, string $slug = 'baraka-hardware', string $phone = '0722 111 222')
    {
        return $this->postJson('/api/stores', [
            'owner' => ['name' => 'Grace Akinyi', 'phone' => $phone, 'password' => 'shop-pass-1'],
            'business' => ['name' => 'Baraka Hardware', 'county' => 'Kajiado', 'town' => 'Kitengela'],
            'planId' => 'business', 'slug' => $slug,
            'billing' => ['period' => 'monthly', 'paymentReference' => $ref],
        ]);
    }

    public function test_signup_pays_then_opens_store(): void
    {
        $this->assertSame(['slug' => 'baraka-hardware', 'available' => true, 'reason' => null],
            $this->getJson('/api/stores/check-slug?slug=Baraka Hardware')->json());
        $pay = $this->payPlan();
        $this->assertSame(['paid', 3500, 'TOPLINE'], [$pay['status'], $pay['amount'], $pay['payee']]);

        $res = $this->signup($pay['reference']);
        $res->assertStatus(201)->assertJson(['store' => ['status' => 'active'], 'user' => ['storeRole' => 'owner']]);
        $this->getJson('/api/storefront/baraka-hardware')->assertOk();
    }

    public function test_signup_sets_password_and_optional_email(): void
    {
        $pay = $this->payPlan();
        $res = $this->postJson('/api/stores', [
            'owner' => ['name' => 'Grace Akinyi', 'phone' => '0722 111 222', 'email' => 'Grace@Example.com', 'password' => 'shop-pass-1'],
            'business' => ['name' => 'Baraka Hardware'], 'planId' => 'business', 'slug' => 'baraka-hardware',
            'billing' => ['period' => 'monthly', 'paymentReference' => $pay['reference']],
        ]);
        $res->assertStatus(201)->assertJson(['user' => ['email' => 'grace@example.com']]);
        foreach (['0722 111 222', 'grace@example.com'] as $login) {
            $this->postJson('/api/auth/login', ['login' => $login, 'password' => 'shop-pass-1'])->assertOk();
        }
    }

    public function test_signup_checks_password_and_email_before_using_the_payment(): void
    {
        $pay = $this->payPlan();
        $base = ['business' => ['name' => 'Baraka Hardware'], 'planId' => 'business', 'slug' => 'baraka-hardware',
            'billing' => ['period' => 'monthly', 'paymentReference' => $pay['reference']]];
        $this->postJson('/api/stores', [...$base, 'owner' => ['name' => 'G', 'phone' => '0722 111 222', 'password' => 'short']])
            ->assertStatus(422)->assertJson(['field' => 'ownerPassword']);
        $this->postJson('/api/stores', [...$base, 'owner' => ['name' => 'G', 'phone' => '0722 111 222', 'password' => 'shop-pass-1', 'email' => 'peter@mjengo.example.com']])
            ->assertStatus(422)->assertJson(['field' => 'ownerEmail']);
        // the payment wasn't used up by the failed attempts
        $this->postJson('/api/stores', [...$base, 'owner' => ['name' => 'G', 'phone' => '0722 111 222', 'password' => 'shop-pass-1']])->assertStatus(201);
    }

    public function test_payment_cannot_open_two_stores(): void
    {
        $pay = $this->payPlan();
        $this->signup($pay['reference'])->assertStatus(201);
        $this->signup($pay['reference'], 'second-shop', '0722 111 333')->assertStatus(402);
    }

    public function test_signup_without_payment_fails(): void
    {
        $this->signup('SUB_FAKE')->assertStatus(402);
    }

    public function test_taken_and_reserved_links(): void
    {
        $this->assertFalse($this->getJson('/api/stores/check-slug?slug=mjengo')->json('available'));
        $this->assertFalse($this->getJson('/api/stores/check-slug?slug=admin')->json('available'));
    }

    public function test_owner_renews_and_upgrades(): void
    {
        $owner = $this->owner();
        $before = $this->store()->next_billing_at;
        $pay = $this->payPlan('0712 345 678', 'pro', headers: $owner['headers'], signup: false);
        $this->assertSame('paid', $pay['status']);
        $mjengo = $this->store();
        $this->assertSame('pro', $mjengo->plan_id);
        $this->assertSame(30, (int) round($before->diffInDays($mjengo->next_billing_at)));
    }

    public function test_billing_sweep_moves_overdue_stores(): void
    {
        $mjengo = $this->store();
        $mjengo->next_billing_at = now()->subDay();
        $mjengo->save();
        Billing::sweep();
        $this->assertSame('past_due', $mjengo->fresh()->status);
        Billing::sweep(now()->addDays(8));
        $this->assertSame('suspended', $mjengo->fresh()->status);
    }

    public function test_admin_suspends_and_reactivates(): void
    {
        $admin = $this->admin();
        $store = $this->store('coast-steel');
        $this->patchJson("/api/admin/stores/{$store->id}", ['status' => 'suspended'], $admin['headers'])->assertJson(['status' => 'suspended']);
        $this->assertSame([], $this->getJson('/api/storefront/coast-steel')->json('products'));
        $this->patchJson("/api/admin/stores/{$store->id}", ['status' => 'active', 'extendDays' => 14], $admin['headers'])->assertJson(['status' => 'active']);
    }

    public function test_simulated_webhook_confirms_payment(): void
    {
        $pay = $this->postJson('/api/billing/subscription/pay', ['phone' => '0722 111 222', 'planId' => 'starter', 'signup' => true])->json();
        $this->postJson('/api/webhooks/paystack', ['event' => 'charge.success', 'data' => ['reference' => $pay['reference']]])->assertOk();
    }

    public function test_signup_period_comes_from_the_payment(): void
    {
        $pay = $this->payPlan(plan: 'starter');
        $res = $this->postJson('/api/stores', [
            'owner' => ['name' => 'G', 'phone' => '0722 111 222', 'password' => 'shop-pass-1'],
            'business' => ['name' => 'Cheap Shop'], 'planId' => 'starter', 'slug' => 'cheap-shop',
            'billing' => ['period' => 'yearly', 'paymentReference' => $pay['reference']], // claims yearly, paid monthly
        ]);
        $this->assertSame('monthly', $res->json('store.billing.period'));
    }

    public function test_signup_can_match_by_mpesa_receipt(): void
    {
        $pay = $this->payPlan();
        $this->postJson('/api/stores', [
            'owner' => ['name' => 'Grace', 'phone' => '0722 111 222', 'password' => 'shop-pass-1'], 'business' => ['name' => 'Receipt Shop'],
            'planId' => 'business', 'slug' => 'receipt-shop', 'billing' => ['period' => 'monthly', 'lastRef' => $pay['mpesaRef']],
        ])->assertStatus(201);
    }

    public function test_signup_works_while_logged_in_as_another_owner(): void
    {
        $owner = $this->owner();
        $mjengo = $this->store();
        [$planBefore, $dueBefore] = [$mjengo->plan_id, (string) $mjengo->next_billing_at];
        $pay = $this->payPlan('0724 000 000', 'pro', headers: $owner['headers']);
        $this->postJson('/api/stores', [
            'owner' => ['name' => 'Jane', 'phone' => '0724 000 000', 'password' => 'shop-pass-1'], 'business' => ['name' => "Jane's"], 'planId' => 'pro',
            'slug' => 'jane-s', 'billing' => ['period' => 'monthly', 'paymentReference' => $pay['reference']],
        ], $owner['headers'])->assertStatus(201);
        $mjengo->refresh();
        $this->assertSame([$planBefore, $dueBefore], [$mjengo->plan_id, (string) $mjengo->next_billing_at]);
    }

    public function test_missing_paystack_keys_stop_payments_politely(): void
    {
        config(['topline.payments_mode' => 'paystack', 'topline.paystack_secret_key' => '']);
        $this->postJson('/api/billing/subscription/pay', ['phone' => '0711222333', 'planId' => 'starter', 'signup' => true])->assertStatus(503);
    }
}
