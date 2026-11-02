<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers now sign up themselves and a Topline admin approves or rejects the sign-up.
 * After approval, profile and product edits go live at once, so the per-edit review
 * columns go. Edits that were still waiting are applied first, so nothing is lost.
 * suppliers.status: pending (applied) | rejected | approved | hidden. review_note = why rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('suppliers')->whereNotNull('draft')->get(['id', 'draft']) as $row) {
            $fields = json_decode($row->draft, true) ?: [];
            if (isset($fields['payment_options'])) {
                $fields['payment_options'] = json_encode($fields['payment_options']);
            }
            DB::table('suppliers')->where('id', $row->id)->update($fields);
        }
        foreach (DB::table('supplier_products')->whereNotNull('draft')->get(['id', 'draft']) as $row) {
            DB::table('supplier_products')->where('id', $row->id)->update(json_decode($row->draft, true) ?: []);
        }
        DB::table('supplier_products')->where('status', 'rejected')->delete();

        Schema::table('suppliers', function (Blueprint $t) {
            $t->dropColumn(['draft', 'review_status']);
        });
        Schema::table('supplier_products', function (Blueprint $t) {
            $t->dropIndex(['status']);
        });
        Schema::table('supplier_products', function (Blueprint $t) {
            $t->dropColumn(['status', 'draft', 'review_note', 'approved_at']);
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $t) {
            $t->json('draft')->nullable();
            $t->string('review_status', 10)->default('');
        });
        Schema::table('supplier_products', function (Blueprint $t) {
            $t->string('status', 10)->default('approved')->index();
            $t->json('draft')->nullable();
            $t->string('review_note', 300)->default('');
            $t->dateTime('approved_at')->nullable();
        });
    }
};
