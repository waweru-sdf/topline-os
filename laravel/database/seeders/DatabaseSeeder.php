<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** `php artisan db:seed` = plans + demo shops. A live site uses `php artisan topline:install` (plans only). */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PlansSeeder::class, DemoSeeder::class]);
    }
}
