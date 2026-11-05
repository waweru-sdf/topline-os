<?php

use App\Support\Text;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Every branch gets its own store page: shopdomain/<branch slug>, with its own contacts, hours and cover photo. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $t) {
            $t->string('slug', 60)->nullable();
            $t->string('phone', 20)->default('');
            $t->string('whatsapp', 15)->default('');
            $t->string('hours', 120)->default('');
            $t->string('cover', 300)->default('');
            $t->unique(['store_id', 'slug']);
        });
        $taken = [];
        foreach (DB::table('branches')->orderBy('created_at')->get(['id', 'store_id', 'name']) as $b) {
            $base = Text::slugify($b->name, 50) ?: 'branch';
            $slug = in_array($base, ['p', 'cart', 'checkout'], true) ? "{$base}-branch" : $base;
            for ($i = 2; isset($taken[$b->store_id][$slug]); $i++) {
                $slug = "{$base}-{$i}";
            }
            $taken[$b->store_id][$slug] = true;
            DB::table('branches')->where('id', $b->id)->update(['slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $t) {
            $t->dropUnique(['store_id', 'slug']);
            $t->dropColumn(['slug', 'phone', 'whatsapp', 'hours', 'cover']);
        });
    }
};
