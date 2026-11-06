<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClearDataTest extends TestCase
{
    public function test_clears_every_shop_but_keeps_admins_plans_and_site(): void
    {
        $this->putJson('/api/admin/site', ['heroTitle' => 'Kept title'], $this->admin()['headers'])->assertOk();
        $this->owner(); // a shop owner login (token) that must go
        $keptUsers = User::whereIn('role', [User::PLATFORM_ADMIN, User::SUPPLIER])->count();
        $suppliers = \App\Models\Supplier::count();
        $this->assertGreaterThan(0, DB::table('supplier_orders')->count() + DB::table('expenses')->count());

        $this->artisan('topline:clear-data')->expectsQuestion('Type CLEAR to continue', 'CLEAR')->assertSuccessful();

        $this->assertSame(0, Store::count());
        $this->assertSame(0, DB::table('products')->count() + DB::table('sales')->count() + DB::table('customers')->count()
            + DB::table('payments')->count() + DB::table('activity_log')->count() + DB::table('page_views')->count());
        $this->assertSame($keptUsers, User::count());
        $this->assertSame($suppliers, \App\Models\Supplier::count()); // supplier partners stay
        $this->assertSame(0, DB::table('supplier_orders')->count() + DB::table('expenses')->count() + DB::table('credit_requests')->count());
        $this->postJson('/api/auth/login', ['login' => '0700 111 222', 'password' => self::PASSWORD])->assertOk(); // supplier login still works
        $this->assertSame(3, Plan::count());
        $this->assertSame('Kept title', $this->getJson('/api/site')->json('heroTitle'));
        $this->postJson('/api/auth/login', ['login' => self::OWNER_PHONE, 'password' => self::PASSWORD])->assertStatus(401);
        $this->admin(); // admins still log in
        $this->assertCount(0, $this->getJson('/api/admin/stores', $this->admin()['headers'])->json());
    }

    public function test_suppliers_option_leaves_only_admins_plans_and_site(): void
    {
        $admins = User::where('role', User::PLATFORM_ADMIN)->count();
        $this->artisan('topline:clear-data', ['--suppliers' => true, '--force' => true])->assertSuccessful();

        $this->assertSame(0, Store::count() + \App\Models\Supplier::count() + DB::table('supplier_products')->count());
        $this->assertSame($admins, User::count());
        $this->postJson('/api/auth/login', ['login' => '0700 111 222', 'password' => self::PASSWORD])->assertStatus(401);
        $this->assertSame([], $this->getJson('/api/suppliers')->json());
        $this->assertSame(3, Plan::count());
        $this->admin();
    }

    public function test_wrong_answer_deletes_nothing(): void
    {
        $this->artisan('topline:clear-data')->expectsQuestion('Type CLEAR to continue', 'no')->assertFailed();
        $this->assertSame(7, Store::count());
    }
}
