<?php

namespace App\Console\Commands;

use App\Models\SiteSetting;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Finishes a GitHub deploy on Hostinger without SSH. The Cron Job runs it every minute
 * (through schedule:run); it does nothing unless something needs doing:
 *  1. no .env yet           → copies .env.example (then fill in the DB_ lines in File Manager)
 *  2. no APP_KEY yet        → generates one
 *  3. new code was deployed → runs database changes (migrations) and refreshes the plans
 *  4. no Topline admin yet  → creates one from FIRST_ADMIN_* in .env
 */
class Deploy extends Command
{
    protected $signature = 'topline:deploy';

    protected $description = 'After a deploy: create .env and the app key if missing, update the database, create the first admin';

    private const VERSION_KEY = '_deploy.version';

    public function handle(): int
    {
        $env = base_path('.env');
        if (! is_file($env)) {
            copy(base_path('.env.example'), $env);
            $this->warn('Created .env from .env.example. Fill in the DB_ lines (hPanel → File Manager).');
        }
        if (! str_contains((string) file_get_contents($env), 'APP_KEY=base64:')) {
            $this->call('key:generate', ['--force' => true]);
        }

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->warn('Database not reachable yet: check DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env. ('.$e->getMessage().')');

            return self::SUCCESS; // tried again next minute
        }

        $version = trim((string) @file_get_contents(base_path('DEPLOYED_VERSION'))) ?: 'local';
        $installed = Schema::hasTable('site_settings') ? SiteSetting::find(self::VERSION_KEY)?->value : null;
        if ($installed !== $version) {
            $this->call('migrate', ['--force' => true]);
            $this->call('db:seed', ['--class' => PlansSeeder::class, '--force' => true]);
            foreach (['config:clear', 'route:clear', 'view:clear'] as $clear) {
                $this->callSilently($clear);
            }
            SiteSetting::updateOrCreate(['key' => self::VERSION_KEY], ['value' => $version, 'updated_at' => now()]);
            Log::info("Topline deployed: {$version}");
            $this->info("Database up to date for version {$version}.");
        }

        $this->firstAdmin();

        return self::SUCCESS;
    }

    private function firstAdmin(): void
    {
        $phone = (string) config('topline.first_admin.phone');
        $password = (string) config('topline.first_admin.password');
        if ($phone === '' || $password === '' || User::where('role', User::PLATFORM_ADMIN)->exists()) {
            return;
        }
        $options = ['name' => config('topline.first_admin.name') ?: 'Topline Admin', 'phone' => $phone, '--password' => $password];
        if ($email = config('topline.first_admin.email')) {
            $options['--email'] = $email;
        }
        $this->call('topline:create-admin', $options);
        Log::warning('First Topline admin created from FIRST_ADMIN_* in .env. Remove FIRST_ADMIN_PASSWORD from .env now.');
    }
}
