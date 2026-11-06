<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plans are paid into Topline's own M-PESA Till: by prompt (request_id = M-PESA CheckoutRequestID)
 * or from the customer's M-PESA menu with the code entered on the site (method till_code, status
 * "checking" until a Topline admin confirms it). payments.status: pending | checking | paid | failed.
 * The landing page's pricing line stops mentioning cards, unless an admin has already reworded it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->string('method', 10)->default('prompt'); // prompt | till_code
            $t->string('request_id', 60)->nullable()->index();
            $t->index('provider_ref');
        });
        $old = 'Pay monthly or yearly by M-PESA or card. No setup fee, and you can change plan at any time.';
        $saved = DB::table('site_settings')->where('key', 'pricingText')->value('value');
        if ($saved !== null && json_decode($saved, true) === $old) {
            DB::table('site_settings')->where('key', 'pricingText')
                ->update(['value' => json_encode('Pay monthly or yearly by M-PESA. No setup fee, and you can change plan at any time.')]);
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropIndex(['provider_ref']);
            $t->dropIndex(['request_id']);
            $t->dropColumn(['method', 'request_id']);
        });
    }
};
