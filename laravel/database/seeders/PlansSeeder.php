<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/** The three price plans. Safe to run again: updates prices and features in place. */
class PlansSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/demo-data.json'), true);
        foreach ($data['plans'] as $p) {
            Plan::updateOrCreate(['id' => $p['id']], [
                'name' => $p['name'], 'price_monthly' => $p['price_monthly'], 'price_yearly' => $p['price_yearly'],
                'blurb' => $p['blurb'], 'popular' => $p['popular'] ?? false, 'limits' => $p['limits'], 'features' => $p['features'],
                'sort_order' => $p['sort_order'], 'active' => true,
            ]);
        }
    }
}
