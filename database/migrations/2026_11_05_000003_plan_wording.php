<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan wording that matches what the plans include (every feature on every plan; limits differ; no
 * "coming soon"). Only plans still showing an earlier default wording are changed: wording a Topline
 * admin has edited (Site content → Price plans) is left alone.
 */
return new class extends Migration
{
    private const OLD = ['starter' => [['Online store at yourshop.toplinehub.co.ke', 'Counter sales in bags, lengths and kg', 'M-PESA and cash payments', 'Stock levels and low-stock alerts', 'WhatsApp receipts'], ['Online store with its own link', 'Till, stock, customer credit and quotations', 'Supplier balances and accounting', 'Up to 150 products', '2 staff logins, 1 branch']], 'business' => [['Everything in Starter', 'Customer credit and statements', 'Quotations that turn into sales', 'Supplier balances', 'Staff roles that hide buying prices', 'Sales and profit reports'], ['Everything in Starter', 'Up to 1,500 products', '8 staff logins, with roles that hide buying prices', '2 branches, each with its own stock']], 'pro' => [['Everything in Business', 'Your own domain (yourshop.co.ke)', 'Up to 5 branches', 'eTIMS invoices (coming soon)', 'Priority WhatsApp support'], ['Everything in Business', 'Unlimited products', '25 staff logins and up to 5 branches', 'Your own domain (yourshop.co.ke)', 'Priority WhatsApp support']]];

    private const NEW = ['starter' => ['Online store at toplinehub.co.ke/s/yourshop', 'Till, stock, customer credit and quotations', 'Supplier balances and accounting', 'Up to 150 products', '2 staff logins, 1 branch'], 'business' => ['Everything in Starter', 'Up to 1,500 products', '8 staff logins, with roles that hide buying prices', '2 branches, each with its own stock and store page (toplinehub.co.ke/s/yourshop/branch)'], 'pro' => ['Everything in Business', 'Unlimited products', '25 staff logins and up to 5 branches', 'Your own domain (yourshop.co.ke), bought and set up by Topline', 'Priority WhatsApp support']];

    public function up(): void
    {
        foreach (self::NEW as $id => $features) {
            $plan = DB::table('plans')->where('id', $id)->first(['features', 'blurb']);
            if ($plan && in_array(json_decode($plan->features, true), self::OLD[$id], true)) {
                $update = ['features' => json_encode($features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
                if ($id === 'business' && $plan->blurb === 'For busy shops that sell on credit and give quotations.') {
                    $update['blurb'] = 'For busy shops with a team, or a second branch.';
                }
                DB::table('plans')->where('id', $id)->update($update);
            }
        }
    }

    public function down(): void {}
};
