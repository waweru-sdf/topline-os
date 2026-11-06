<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Suppliers;
use App\Services\Uploads;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Start over with an empty platform, keeping only what Topline itself owns:
 * Topline admin logins, the price plans, the landing-page content and its pictures,
 * and the supplier partners (their logins, profiles and products; their orders from shops go).
 * With --suppliers the supplier partners go too, leaving only the admins, plans and site content.
 * Everything shop-related goes: shops, owners and staff, products, sales, customers,
 * payments, payouts, quotes, shop photos, activity and traffic stats.
 */
class ClearData extends Command
{
    protected $signature = 'topline:clear-data
        {--suppliers : also delete the supplier partners (logins, profiles, products)}
        {--force : skip the "type CLEAR" question}';

    protected $description = 'Delete every shop and its data; keep Topline admins, plans and site content (and supplier partners, unless --suppliers)';

    /** Children before parents, so foreign keys are never in the way. */
    private const TABLES = ['activity_log', 'incidents', 'request_stats', 'page_views', 'store_reviews', 'supplier_account_entries', 'supplier_accounts', 'expenses', 'credit_requests', 'supplier_orders',
        'quotes', 'payouts', 'payments',
        'sale_items', 'sales', 'customer_payments', 'customers', 'stock_movements', 'branch_stock', 'products', 'store_members', 'branches', 'stores'];

    public function handle(): int
    {
        $admins = User::where('role', User::PLATFORM_ADMIN)->get(['id', 'name', 'phone']);
        if ($admins->isEmpty()) {
            $this->error('There is no Topline admin, so nobody could log in afterwards. Create one first: php artisan topline:create-admin');

            return self::FAILURE;
        }

        $suppliers = (bool) $this->option('suppliers');
        $this->warn('This permanently deletes every shop and all its data (products, sales, customers, payments, photos, activity)'
            .($suppliers ? ', and every supplier partner.' : '.'));
        $this->line('Kept: the plans, the landing-page content, '.($suppliers ? '' : 'supplier partners, ').'and these Topline admins:');
        foreach ($admins as $a) {
            $this->line("  - {$a->name} ({$a->phone})");
        }
        if (! $this->option('force') && $this->ask('Type CLEAR to continue') !== 'CLEAR') {
            $this->info('Nothing was deleted.');

            return self::FAILURE;
        }

        $keepIds = User::whereIn('role', $suppliers ? [User::PLATFORM_ADMIN] : [User::PLATFORM_ADMIN, User::SUPPLIER])->pluck('id')->all();
        // Shop photos, plus supplier logos and product photos when suppliers go (site pictures belong to admins and stay).
        $photos = fn () => DB::table('uploads')->where(fn ($q) => $q->whereNotNull('store_id')->orWhereNotIn('user_id', $keepIds));
        $photoIds = $photos()->pluck('id');
        $counts = [];

        DB::transaction(function () use (&$counts, $keepIds, $suppliers, $photos) {
            foreach ([...self::TABLES, ...($suppliers ? ['supplier_products', 'suppliers'] : [])] as $table) {
                $counts[$table] = DB::table($table)->delete();
            }
            $counts['uploaded photos'] = $photos()->delete();
            $counts[$suppliers ? 'users (owners, staff, suppliers)' : 'users (owners, staff)'] = DB::table('users')->whereNotIn('id', $keepIds)->delete();
            $counts['logins of deleted users'] = DB::table('personal_access_tokens')->whereNotIn('tokenable_id', $keepIds)->delete();
        });

        Suppliers::forgetPublicList(); // their "works with N shops" counts are now 0 (or they're gone)
        foreach ($photoIds as $id) {
            @unlink(Uploads::folder().'/'.basename($id));
        }

        $this->table(['What', 'Rows deleted'], collect($counts)->map(fn ($n, $what) => [$what, $n])->values()->all());
        $this->info('Done. The platform is empty except for the Topline admins, the plans and the site content'.($suppliers ? '.' : ', and the supplier partners.'));

        return self::SUCCESS;
    }
}
