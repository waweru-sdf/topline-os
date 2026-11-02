<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Uploads;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Start over with an empty platform, keeping only what Topline itself owns:
 * Topline admin logins, the price plans, the landing-page content and its pictures,
 * and the supplier partners (their logins, profiles and products; their orders from shops go).
 * Everything shop-related goes: shops, owners and staff, products, sales, customers,
 * payments, payouts, quotes, shop photos, activity and traffic stats.
 */
class ClearData extends Command
{
    protected $signature = 'topline:clear-data {--force : skip the "type CLEAR" question}';

    protected $description = 'Delete every shop and its data; keep Topline admins, plans, site content and supplier partners';

    /** Children before parents, so foreign keys are never in the way. */
    private const TABLES = ['activity_log', 'incidents', 'request_stats', 'page_views', 'expenses', 'credit_requests', 'supplier_orders',
        'quotes', 'payouts', 'payments',
        'sale_items', 'sales', 'customer_payments', 'customers', 'stock_movements', 'products', 'store_members', 'stores'];

    public function handle(): int
    {
        $admins = User::where('role', User::PLATFORM_ADMIN)->get(['id', 'name', 'phone']);
        if ($admins->isEmpty()) {
            $this->error('There is no Topline admin, so nobody could log in afterwards. Create one first: php artisan topline:create-admin');

            return self::FAILURE;
        }

        $this->warn('This permanently deletes every shop and all its data (products, sales, customers, payments, photos, activity).');
        $this->line('Kept: the plans, the landing-page content, supplier partners, and these Topline admins:');
        foreach ($admins as $a) {
            $this->line("  - {$a->name} ({$a->phone})");
        }
        if (! $this->option('force') && $this->ask('Type CLEAR to continue') !== 'CLEAR') {
            $this->info('Nothing was deleted.');

            return self::FAILURE;
        }

        $shopPhotos = DB::table('uploads')->whereNotNull('store_id')->pluck('id');
        $keepIds = User::whereIn('role', [User::PLATFORM_ADMIN, User::SUPPLIER])->pluck('id')->all();
        $counts = [];

        DB::transaction(function () use (&$counts, $keepIds) {
            foreach (self::TABLES as $table) {
                $counts[$table] = DB::table($table)->delete();
            }
            $counts['uploads (shop photos)'] = DB::table('uploads')->whereNotNull('store_id')->delete();
            $counts['users (owners, staff)'] = DB::table('users')->whereNotIn('id', $keepIds)->delete();
            $counts['logins of deleted users'] = DB::table('personal_access_tokens')->whereNotIn('tokenable_id', $keepIds)->delete();
        });

        foreach ($shopPhotos as $id) {
            @unlink(Uploads::folder().'/'.basename($id));
        }

        $this->table(['What', 'Rows deleted'], collect($counts)->map(fn ($n, $what) => [$what, $n])->values()->all());
        $this->info('Done. The platform is empty except for the Topline admins, the plans and the site content.');

        return self::SUCCESS;
    }
}
