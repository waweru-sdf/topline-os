<?php

namespace App\Console\Commands;

use Database\Seeders\PlansSeeder;
use Illuminate\Console\Command;

class Install extends Command
{
    protected $signature = 'topline:install {--demo : also load the 7 demo shops (password topline123)}';

    protected $description = 'Create the database tables and the price plans (and optionally the demo shops)';

    public function handle(): int
    {
        if ($this->option('demo') && app()->isProduction()
            && ! $this->confirm('The demo shops and demo admins all use the password "topline123". Load them on this LIVE site anyway?', false)) {
            $this->warn('Stopped. Run it again without --demo for a clean live site.');

            return self::FAILURE;
        }
        $this->call('migrate', ['--force' => true]);
        $this->call('db:seed', ['--class' => PlansSeeder::class, '--force' => true]);
        if ($this->option('demo')) {
            $this->call('db:seed', ['--class' => \Database\Seeders\DemoSeeder::class, '--force' => true]);
        }
        $this->newLine();
        $this->info('Database ready.'.($this->option('demo') ? ' Demo shops loaded.' : ''));
        $this->line('Next: php artisan topline:create-admin "Your Name" 07XXXXXXXX --email=you@example.com');

        return self::SUCCESS;
    }
}
