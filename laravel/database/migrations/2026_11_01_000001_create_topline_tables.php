<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every Topline table. Times are stored in UTC; money is whole KES (integers).
 * Every Topline table. MySQL/MariaDB on Hostinger; SQLite works for local tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $t) {
            $t->string('id', 32)->primary();
            $t->string('name', 60);
            $t->integer('price_monthly');
            $t->integer('price_yearly');
            $t->string('blurb', 200)->default('');
            $t->boolean('popular')->default(false);
            $t->json('limits')->nullable();     // {"products": 150, "staff": 2, "branches": 1}
            $t->json('features')->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('active')->default(true);
        });

        Schema::create('users', function (Blueprint $t) {
            $t->string('id', 32)->primary();
            $t->string('name', 120);
            $t->string('phone', 15)->unique();          // 2547XXXXXXXX
            $t->string('email', 160)->nullable()->unique(); // lower-case
            $t->string('role', 20)->default('vendor');  // vendor | platform_admin
            $t->boolean('is_active')->default(true);
            $t->string('password', 255)->default('');
            $t->boolean('must_change_password')->default(false); // after an admin reset
            $t->integer('failed_logins')->default(0);
            $t->dateTime('locked_until')->nullable();
            $t->dateTime('last_login_at')->nullable();
            $t->dateTime('created_at')->nullable();
        });

        Schema::create('stores', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('slug', 40)->unique();
            $t->string('name', 120);
            $t->string('tagline', 200)->default('');
            $t->string('logo', 300)->default('');
            $t->string('county', 40)->default('');
            $t->string('town', 80)->default('');
            $t->string('address', 200)->default('');
            $t->string('phone', 20)->default('');
            $t->string('whatsapp', 15)->default('');
            $t->string('hours', 120)->default('Mon–Sat 8:00–18:00');
            $t->string('accent', 7)->default('#F5A623');
            $t->string('cover', 300)->default('aisle');
            // Where the shop receives money: till | paybill | pochi | send_money
            $t->string('payout_method', 12)->default('till');
            $t->string('payout_number', 20)->default('');
            $t->string('payout_account', 40)->default('');
            $t->string('payout_name', 120)->default('');
            $t->boolean('accepts_direct_payment')->default(true);
            // Delivery: zones with a fee each, or "quote" (agreed with the shop)
            $t->boolean('delivery_enabled')->default(false);
            $t->string('delivery_mode', 10)->default('zones');
            $t->json('delivery_zones')->nullable();
            $t->integer('delivery_free_over')->nullable();
            $t->text('delivery_note')->nullable();
            $t->string('custom_domain', 120)->nullable()->unique();
            $t->string('plan_id', 32);
            $t->string('billing_period', 10)->default('monthly');
            $t->string('status', 20)->default('draft')->index(); // draft | active | past_due | suspended
            $t->dateTime('next_billing_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->foreign('plan_id')->references('id')->on('plans');
        });

        Schema::create('store_members', function (Blueprint $t) {
            $t->id();
            $t->string('store_id', 40)->index();
            $t->string('user_id', 32)->index();
            $t->string('role', 20)->default('owner'); // owner | manager | cashier
            $t->dateTime('created_at')->nullable();
            $t->unique(['store_id', 'user_id']);
            $t->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('products', function (Blueprint $t) {
            $t->string('id', 32)->primary();
            $t->string('store_id', 40)->index();
            $t->string('slug', 60);
            $t->string('name', 160);
            $t->string('category_id', 32)->index();
            $t->string('unit', 40)->default('piece');
            $t->integer('sell_price');
            $t->integer('buy_price')->default(0);  // never shown to customers or cashiers
            $t->integer('stock')->default(0);      // may go below zero for counter sales
            $t->integer('reorder_at')->default(10);
            $t->string('image', 300)->default(''); // ready-made picture key or upload URL
            $t->json('gallery')->nullable();       // up to 3 more photos
            $t->string('size', 80)->default('');   // main measurement, e.g. "12 mm × 12 m"
            $t->json('specs')->nullable();         // up to 8 [{"label", "value"}]
            $t->text('description')->nullable();
            $t->json('alt_units')->nullable();
            $t->string('vat_class', 12)->default('standard');
            $t->boolean('published')->default(true);
            $t->dateTime('created_at')->nullable();
            $t->unique(['store_id', 'slug']);
            $t->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });

        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->string('store_id', 40)->index();
            $t->string('product_id', 32)->index();
            $t->integer('delta');
            $t->string('reason', 20); // sale | online_order | adjust | released | cancelled | payment_failed
            $t->string('reference', 40)->default('');
            $t->string('user_id', 32)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->string('id', 32)->primary();
            $t->string('store_id', 40)->index();
            $t->string('name', 120);
            $t->string('phone', 20)->default('');
            $t->integer('credit_limit')->default(0);
            $t->integer('balance')->default(0); // owed to the shop
            $t->dateTime('due_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });

        Schema::create('customer_payments', function (Blueprint $t) {
            $t->id();
            $t->string('store_id', 40)->index();
            $t->string('customer_id', 32)->index();
            $t->integer('amount');
            $t->string('method', 10)->default('mpesa');
            $t->string('recorded_by', 32)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $t->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });

        // Counter sales (channel "pos") and online orders (channel "online"). Shown as "S-<number>".
        Schema::create('sales', function (Blueprint $t) {
            $t->id('number');
            $t->string('store_id', 40)->index();
            $t->string('channel', 10)->default('pos')->index();
            $t->string('status', 24)->default('completed')->index();
            $t->string('method', 10)->default('cash');
            $t->string('payment_mode', 10)->nullable(); // online: prompt | direct
            $t->integer('subtotal')->default(0);
            $t->integer('delivery_fee')->default(0);
            $t->integer('total')->default(0);
            $t->string('staff_name', 120)->nullable();
            $t->string('staff_id', 32)->nullable();
            $t->string('customer_id', 32)->nullable();
            $t->string('customer_name', 120)->nullable();
            $t->string('customer_phone', 15)->nullable()->index();
            $t->string('delivery_type', 10)->nullable();
            $t->string('delivery_zone', 80)->nullable();
            $t->string('delivery_address', 200)->nullable();
            $t->text('delivery_notes')->nullable();
            $t->string('payment_reference', 40)->nullable()->index();
            $t->string('mpesa_ref', 20)->nullable();
            $t->dateTime('created_at')->nullable()->index();
            $t->dateTime('paid_at')->nullable();
            $t->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $t->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
        });

        Schema::create('sale_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('sale_number')->index();
            $t->string('product_id', 32)->nullable();
            $t->string('name', 160);
            $t->string('unit', 40);
            $t->integer('qty');
            $t->integer('price');
            $t->integer('cost')->default(0);
            $t->foreign('sale_number')->references('number')->on('sales')->cascadeOnDelete();
            $t->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });

        // Money coming IN: one M-PESA prompt (simulated or Paystack).
        Schema::create('payments', function (Blueprint $t) {
            $t->string('reference', 40)->primary();
            $t->string('kind', 15);       // subscription | order
            $t->string('status', 10)->default('pending')->index(); // pending | paid | failed
            $t->string('provider', 15);   // simulation | paystack
            $t->integer('amount');
            $t->string('phone', 15);
            $t->string('payee', 120)->default('TOPLINE');
            $t->string('plan_id', 32)->nullable();
            $t->string('period', 10)->nullable();
            $t->boolean('claimed')->default(false); // sign-up payment used to open a store
            $t->string('store_id', 40)->nullable()->index();
            $t->unsignedBigInteger('sale_number')->nullable();
            $t->string('simulated_answer', 10)->nullable(); // approve | decline (test phone)
            $t->string('provider_ref', 40)->nullable();      // M-PESA receipt
            $t->string('failure_reason', 200)->nullable();
            $t->dateTime('created_at')->nullable()->index();
            $t->dateTime('paid_at')->nullable();
            $t->foreign('plan_id')->references('id')->on('plans');
            $t->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
            $t->foreign('sale_number')->references('number')->on('sales')->nullOnDelete();
        });

        // Money going OUT: an order's takings sent to the shop.
        Schema::create('payouts', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 40)->unique();
            $t->string('store_id', 40)->index();
            $t->unsignedBigInteger('sale_number')->nullable();
            $t->integer('gross');
            $t->integer('commission')->default(0);
            $t->integer('amount');
            $t->string('method', 12);
            $t->string('destination', 80);
            $t->string('status', 10)->default('pending')->index();
            $t->string('provider_ref', 60)->nullable();
            $t->string('failure_reason', 200)->nullable();
            $t->dateTime('created_at')->nullable()->index();
            $t->dateTime('paid_at')->nullable();
            $t->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $t->foreign('sale_number')->references('number')->on('sales')->nullOnDelete();
        });

        Schema::create('quotes', function (Blueprint $t) {
            $t->string('id', 32)->primary();
            $t->string('reference', 16)->index();
            $t->string('store_id', 40)->index();
            $t->string('name', 120);
            $t->string('phone', 15);
            $t->text('items');
            $t->string('site', 200)->default('');
            $t->string('status', 20)->default('new'); // new | replied | won | lost
            $t->dateTime('created_at')->nullable();
            $t->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });

        Schema::create('site_settings', function (Blueprint $t) {
            $t->string('key', 60)->primary();
            $t->json('value')->nullable();
            $t->dateTime('updated_at')->nullable();
        });

        Schema::create('uploads', function (Blueprint $t) {
            $t->string('id', 40)->primary(); // also the file name
            $t->string('store_id', 40)->nullable()->index();
            $t->string('user_id', 32);
            $t->string('content_type', 40);
            $t->integer('size');
            $t->binary('data')->nullable(); // only when UPLOAD_STORAGE=database
            $t->dateTime('created_at')->nullable();
        });
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement('ALTER TABLE uploads MODIFY data LONGBLOB NULL');
        }

        Schema::create('page_views', function (Blueprint $t) {
            $t->id();
            $t->date('day')->index();
            $t->string('store_id', 40)->nullable()->index();
            $t->string('page', 20); // landing | store | product
            $t->integer('views')->default(0);
            $t->unique(['day', 'store_id', 'page']);
        });

        Schema::create('request_stats', function (Blueprint $t) {
            $t->dateTime('hour')->primary();
            $t->integer('requests')->default(0);
            $t->integer('client_errors')->default(0);
            $t->integer('server_errors')->default(0);
            $t->bigInteger('total_ms')->default(0);
            $t->integer('slowest_ms')->default(0);
        });

        Schema::create('incidents', function (Blueprint $t) {
            $t->id();
            $t->string('fingerprint', 64)->unique();
            $t->string('kind', 20);
            $t->string('title', 200);
            $t->text('detail')->nullable();
            $t->string('path', 200)->default('');
            $t->integer('count')->default(1);
            $t->dateTime('first_seen')->nullable();
            $t->dateTime('last_seen')->nullable()->index();
            $t->boolean('resolved')->default(false)->index();
            $t->integer('notified_count')->default(0);
        });

        Schema::create('activity_log', function (Blueprint $t) {
            $t->id();
            $t->string('store_id', 40)->nullable()->index();
            $t->string('actor_id', 32)->nullable();
            $t->string('actor_name', 120)->default('');
            $t->string('kind', 30)->index();
            $t->string('summary', 300);
            $t->json('data')->nullable();
            $t->dateTime('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        foreach (['activity_log', 'incidents', 'request_stats', 'page_views', 'uploads', 'site_settings', 'quotes', 'payouts',
            'payments', 'sale_items', 'sales', 'customer_payments', 'customers', 'stock_movements', 'products',
            'store_members', 'stores', 'users', 'plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
