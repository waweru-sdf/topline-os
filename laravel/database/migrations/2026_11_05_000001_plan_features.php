<?php

use App\Support\Text;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the plans promise: staff logins (store_members.branch_id), branches with their own stock,
 * the shop's own domain, supplier balances, and quotations that turn into sales.
 *
 * Stock: the main branch's stock stays in products.stock (the online store sells from the main
 * branch, so online orders work as before); every other branch's stock is in branch_stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('store_id', 40)->index();
            $t->string('name', 80);
            $t->string('location', 160)->default('');
            $t->boolean('is_main')->default(false);
            $t->dateTime('created_at')->nullable();
            $t->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });

        Schema::create('branch_stock', function (Blueprint $t) {
            $t->string('branch_id', 40);
            $t->string('product_id', 32)->index();
            $t->integer('qty')->default(0); // may go below zero for counter sales, like products.stock
            $t->primary(['branch_id', 'product_id']);
            $t->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::table('sales', function (Blueprint $t) {
            $t->string('branch_id', 40)->nullable()->index(); // null: main branch (and every sale before branches)
        });
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->string('branch_id', 40)->nullable();
        });
        Schema::table('store_members', function (Blueprint $t) {
            $t->string('branch_id', 40)->nullable(); // staff who work at one branch only
        });
        Schema::table('stores', function (Blueprint $t) {
            $t->string('domain_status', 10)->default(''); // '' none | pending | connected
        });

        Schema::table('quotes', function (Blueprint $t) {
            $t->string('source', 10)->default('customer'); // customer (asked on the store) | shop (made at the till)
            $t->json('lines')->nullable();                  // priced items: [{productId, name, unit, qty, price}]
            $t->integer('total')->nullable();
            $t->string('sale_id', 32)->nullable();          // the sale it turned into
        });

        Schema::create('supplier_accounts', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('store_id', 40)->index();
            $t->string('name', 120);
            $t->string('phone', 15)->default('');
            $t->string('note', 300)->default('');
            $t->integer('balance')->default(0); // what the shop owes them now
            $t->dateTime('created_at')->nullable();
            $t->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });
        Schema::create('supplier_account_entries', function (Blueprint $t) {
            $t->id();
            $t->string('account_id', 40)->index();
            $t->string('kind', 8);          // bill (they supplied goods on credit) | payment (the shop paid them)
            $t->integer('amount');
            $t->date('entry_date');
            $t->date('due_on')->nullable(); // bills only
            $t->string('note', 300)->default('');
            $t->string('user_name', 120)->default('');
            $t->dateTime('created_at')->nullable();
            $t->foreign('account_id')->references('id')->on('supplier_accounts')->cascadeOnDelete();
        });

        // Every existing shop gets its main branch.
        foreach (DB::table('stores')->get(['id', 'town', 'created_at']) as $store) {
            DB::table('branches')->insert(['id' => Text::newId('br'), 'store_id' => $store->id, 'name' => 'Main shop',
                'location' => $store->town ?? '', 'is_main' => true, 'created_at' => $store->created_at ?? now()]);
        }
        // Own domain was promised on Pro; record it as a plan limit like the others.
        foreach (DB::table('plans')->get(['id', 'limits']) as $plan) {
            $limits = json_decode($plan->limits ?? '{}', true) ?: [];
            if (! array_key_exists('customDomain', $limits)) {
                $limits['customDomain'] = $plan->id === 'pro';
                DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($limits)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_account_entries');
        Schema::dropIfExists('supplier_accounts');
        Schema::table('quotes', fn (Blueprint $t) => $t->dropColumn(['source', 'lines', 'total', 'sale_id']));
        Schema::table('stores', fn (Blueprint $t) => $t->dropColumn('domain_status'));
        Schema::table('store_members', fn (Blueprint $t) => $t->dropColumn('branch_id'));
        Schema::table('stock_movements', fn (Blueprint $t) => $t->dropColumn('branch_id'));
        Schema::table('sales', function (Blueprint $t) {
            $t->dropIndex(['branch_id']);
            $t->dropColumn('branch_id');
        });
        Schema::dropIfExists('branch_stock');
        Schema::dropIfExists('branches');
    }
};
