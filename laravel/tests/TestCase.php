<?php

namespace Tests;

use App\Models\Product;
use App\Models\Store;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

/**
 * Every test starts from the demo data (7 shops; password "topline123"), with payments
 * simulated and auto-approved, like the reference backend's tests.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    public const OWNER_PHONE = '0712 345 678';  // Peter Kamau, Mjengo Hardware
    public const ADMIN_PHONE = '0700 000 000';  // Topline Admin
    public const PASSWORD = 'topline123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['topline.payments_mode' => 'simulation', 'topline.simulation_approval' => 'auto',
            'topline.simulated_payment_delay_seconds' => 0, 'topline.sms_mode' => 'simulation', 'topline.cron_secret' => '', 'topline.upload_folder' => sys_get_temp_dir().'/topline-test-uploads']);
        $this->seed([PlansSeeder::class, DemoSeeder::class]);
    }

    /** Logs in and returns ['headers' => [...], 'user' => [...], 'token' => '...']. */
    protected function login(string $login, string $password = self::PASSWORD): array
    {
        $res = $this->postJson('/api/auth/login', ['login' => $login, 'password' => $password]);
        $res->assertOk();

        return ['headers' => $this->auth($res->json('token')), 'user' => $res->json('user'), 'token' => $res->json('token'),
            'storeId' => $res->json('user.storeId')];
    }

    protected function auth(?string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    protected function owner(): array
    {
        return $this->login(self::OWNER_PHONE);
    }

    protected function admin(): array
    {
        return $this->login(self::ADMIN_PHONE);
    }

    /** A cashier at Mjengo, created directly in the database. */
    protected function cashier(): array
    {
        $user = \App\Models\User::create(['name' => 'Esther Wairimu', 'phone' => '254733000111',
            'password' => \Illuminate\Support\Facades\Hash::make('cashier-pass-1')]);
        \App\Models\StoreMember::create(['store_id' => $this->store()->id, 'user_id' => $user->id, 'role' => 'cashier']);

        return [...$this->login('0733 000 111', 'cashier-pass-1'), 'storeId' => $this->store()->id];
    }

    protected function store(string $slug = 'mjengo'): Store
    {
        return Store::where('slug', $slug)->firstOrFail();
    }

    protected function product(string $slug, string $store = 'mjengo'): Product
    {
        return Product::where('store_id', $this->store($store)->id)->where('slug', $slug)->firstOrFail();
    }

    protected function order(string $store, array $items, array $extra = []): TestResponse
    {
        return $this->postJson("/api/storefront/{$store}/orders",
            ['customer' => ['name' => 'Daniel K', 'phone' => '0711 222 333'], 'items' => $items, ...$extra]);
    }

    /** Send the plan prompt and poll it to the end (auto-approved in tests). */
    protected function payPlan(string $phone = '0722 111 222', string $plan = 'business', string $period = 'monthly', array $headers = [], bool $signup = true): array
    {
        $res = $this->postJson('/api/billing/subscription/pay', ['phone' => $phone, 'planId' => $plan, 'period' => $period, 'signup' => $signup], $headers);
        $res->assertStatus(202);

        return $this->getJson('/api/billing/payments/'.$res->json('reference'))->json();
    }
}
