<?php

namespace App\Services;

use App\Models\Plan;
use App\Support\ApiError;
use App\Support\Input;
use Illuminate\Support\Facades\DB;

/** Topline admins edit the price plans: name, prices, description, what's included and the product limit. */
class Plans
{
    public static function update(Plan $plan, array $data): Plan
    {
        $features = $data['features'] ?? [];
        if (! is_array($features)) {
            throw ApiError::invalid('List what the plan includes.', 'features');
        }
        $features = array_values(array_filter(array_map(fn ($f) => is_string($f) ? trim($f) : '', $features)));
        if (count($features) > 15 || collect($features)->contains(fn ($f) => mb_strlen($f) > 120)) {
            throw ApiError::invalid('Up to 15 lines, each under 120 characters.', 'features');
        }
        $limit = $data['productLimit'] ?? null;
        $limit = $limit === null || $limit === '' ? null : Input::integer($data, 'productLimit', false, 1, 'Enter at least 1 product, or leave it empty for unlimited.');

        $fields = [
            'name' => Input::text($data, 'name', true, 40, 'Enter the plan name.'),
            'price_monthly' => self::price($data, 'priceMonthly'),
            'price_yearly' => self::price($data, 'priceYearly'),
            'blurb' => Input::text($data, 'blurb', false, 200),
            'features' => $features,
            'limits' => [...($plan->limits ?? []), 'products' => $limit],
            'popular' => filter_var($data['popular'] ?? false, FILTER_VALIDATE_BOOL),
        ];
        DB::transaction(function () use ($plan, $fields) {
            if ($fields['popular']) {
                Plan::where('id', '!=', $plan->id)->update(['popular' => false]); // one "most shops pick this"
            }
            $plan->fill($fields)->save();
        });

        return $plan;
    }

    private static function price(array $data, string $key): int
    {
        $price = Input::integer($data, $key, true, 0, 'Enter a price in shillings (0 or more).');
        if ($price > 1_000_000) {
            throw ApiError::invalid('Enter a price up to KSh 1,000,000.', $key);
        }

        return $price;
    }
}
