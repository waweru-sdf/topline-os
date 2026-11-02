<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connect to suppliers (supplier profiles, their products, orders from shops),
 * stock credit requests, and shop expenses for accounting.
 *
 * Profiles and products are reviewed by a Topline admin. Until the first approval the
 * supplier edits the record directly; after that, edits wait in `draft` for approval
 * and shops keep seeing the approved version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('user_id', 32)->nullable()->index(); // the supplier's login
            $t->string('slug', 60)->unique();
            $t->string('name', 120);
            $t->string('logo', 300)->default('');
            $t->text('description');
            $t->string('categories', 300)->default('');      // comma-separated, e.g. "Cement, Steel"
            $t->string('location', 120)->default('');
            $t->string('phone', 15)->default('');
            $t->string('email', 160)->default('');
            $t->string('website', 200)->default('');
            $t->string('min_order', 120)->default('');
            $t->string('delivery_info', 300)->default('');
            $t->json('payment_options')->nullable();         // codes from Suppliers::PAYMENT_OPTIONS
            $t->text('payment_instructions')->nullable();     // shown to shops only, never on the landing page
            $t->string('status', 10)->default('pending')->index(); // pending | approved | hidden
            $t->json('draft')->nullable();                    // edits waiting for approval
            $t->string('review_status', 10)->default('');     // '' | pending | rejected
            $t->string('review_note', 300)->default('');
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('supplier_products', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('supplier_id', 40)->index();
            $t->string('name', 160);
            $t->text('description');
            $t->string('unit', 40)->default('piece');
            $t->integer('price')->nullable();                 // whole KES; null = "price on request"
            $t->integer('min_qty')->default(1);
            $t->boolean('in_stock')->default(true);
            $t->string('image', 300)->default('');
            $t->string('status', 10)->default('pending')->index(); // pending | approved | rejected
            $t->json('draft')->nullable();
            $t->string('review_note', 300)->default('');
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->foreign('supplier_id')->references('id')->on('suppliers')->cascadeOnDelete();
        });

        Schema::create('supplier_orders', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('reference', 16)->index();             // "PO-8F2K1Q" shown to both sides
            $t->string('supplier_id', 40)->index();
            $t->string('store_id', 40)->index();
            $t->string('placed_by', 32)->nullable();
            $t->string('contact_name', 120);
            $t->string('contact_phone', 15);
            $t->json('items');                                // [{productId, name, unit, qty, price}]
            $t->integer('estimated_total')->nullable();       // sum of lines with a known price
            $t->string('delivery_location', 200)->default('');
            $t->string('payment_option', 20);
            $t->text('notes')->nullable();
            $t->string('status', 12)->default('new')->index(); // new | contacted | completed | declined | cancelled
            $t->dateTime('created_at')->nullable()->index();
            $t->dateTime('updated_at')->nullable();
            $t->foreign('supplier_id')->references('id')->on('suppliers')->cascadeOnDelete();
            $t->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });

        Schema::create('credit_requests', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('reference', 16)->index();
            $t->string('store_id', 40)->index();
            $t->string('user_id', 32)->nullable();
            $t->integer('amount');
            $t->string('purpose', 20);
            $t->integer('period_months');
            $t->integer('monthly_sales')->nullable();         // the owner's own estimate
            $t->string('contact_name', 120);
            $t->string('contact_phone', 15);
            $t->text('notes')->nullable();
            $t->string('supplier_order_id', 40)->nullable();
            $t->string('status', 12)->default('new')->index(); // new | reviewing | approved | declined | closed
            $t->text('admin_note')->nullable();
            $t->dateTime('created_at')->nullable()->index();
            $t->dateTime('updated_at')->nullable();
            $t->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });

        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->string('store_id', 40)->index();
            $t->date('spent_on')->index();
            $t->string('category', 20);
            $t->integer('amount');
            $t->string('method', 10)->default('cash');
            $t->string('description', 200)->default('');
            $t->string('recorded_by', 32)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['expenses', 'credit_requests', 'supplier_orders', 'supplier_products', 'suppliers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
