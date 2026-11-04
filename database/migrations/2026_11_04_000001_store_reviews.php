<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Reviews of hardware shops, sent from the public Reviews page. Only Topline admins read them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_reviews', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('store_id', 40)->index();
            $t->unsignedTinyInteger('rating');           // 1–5
            $t->text('body');
            $t->date('visited_on');                      // when the reviewer dealt with the shop
            $t->string('reviewer_name', 120);
            $t->string('reviewer_phone', 15)->default(''); // optional, for Topline to follow up
            $t->string('status', 8)->default('new')->index(); // new | done
            $t->dateTime('created_at')->nullable()->index();
            $t->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_reviews');
    }
};
