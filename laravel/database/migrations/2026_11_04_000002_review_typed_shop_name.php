<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reviewers now type the shop's name (the public page no longer lists every shop).
 * store_id is filled in only when the name or the store's own link identifies one shop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_reviews', function (Blueprint $t) {
            $t->string('store_name', 120)->default('');
        });
        Schema::table('store_reviews', function (Blueprint $t) {
            $t->string('store_id', 40)->nullable()->change();
        });
        foreach (DB::table('store_reviews')->join('stores', 'stores.id', '=', 'store_reviews.store_id')->get(['store_reviews.id', 'stores.name']) as $row) {
            DB::table('store_reviews')->where('id', $row->id)->update(['store_name' => $row->name]);
        }
    }

    public function down(): void
    {
        DB::table('store_reviews')->whereNull('store_id')->delete();
        Schema::table('store_reviews', function (Blueprint $t) {
            $t->string('store_id', 40)->nullable(false)->change();
            $t->dropColumn('store_name');
        });
    }
};
