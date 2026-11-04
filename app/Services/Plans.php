<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Store;
use App\Support\ApiError;
use App\Support\Input;
use Illuminate\Support\Facades\DB;

/**
 * Price plans. Every plan includes every feature; plans differ by price and limits:
 * products, staff logins, branches, and whether the shop can use its own domain.
 * Topline admins edit them in Site content → Price plans.
 */
class Plans
{
    /** A store's plan limit: an int (null = unlimited) for products/staff/branches, a bool for customDomain. */
    public static function limit(Store $store, string $key): int|bool|null
    {
        $limits = $store->plan?->limits ?? [];

        return match ($key) {
            'customDomain' => (bool) ($limits['customDomain'] ?? false),
            'branches' => array_key_exists('branches', $limits) ? ($limits['branches'] === null ? null : max(1, (int) $limits['branches'])) : 1,
            default => isset($limits[$key]) ? (int) $limits[$key] : null,
        };
    }

    /** Fails with a clear message when a store is at its plan's limit for $key, counting $used. */
    public static function checkLimit(Store $store, string $key, int $used, string $what, string $field = ''): void
    {
        $limit = self::limit($store, $key);
        if ($limit !== null && $used >= $limit) {
            $plan = $store->plan?->name ?? 'your';
            throw ApiError::invalid("The {$plan} plan includes {$limit} {$what}. Change plan under Plan & billing to add more.", $field ?: null);
        }
    }

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

        $fields = [
            'name' => Input::text($data, 'name', true, 40, 'Enter the plan name.'),
            'price_monthly' => self::price($data, 'priceMonthly'),
            'price_yearly' => self::price($data, 'priceYearly'),
            'blurb' => Input::text($data, 'blurb', false, 200),
            'features' => $features,
            'limits' => [...$old = ($plan->limits ?? []),
                'products' => self::optionalLimit($data, 'productLimit', 'products', $old['products'] ?? null),
                'staff' => self::optionalLimit($data, 'staffLimit', 'staff logins', $old['staff'] ?? null, 0),
                'branches' => self::optionalLimit($data, 'branchLimit', 'branches', $old['branches'] ?? 1),
                'customDomain' => array_key_exists('customDomain', $data) ? filter_var($data['customDomain'], FILTER_VALIDATE_BOOL) : (bool) ($old['customDomain'] ?? false),
            ],
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

    /** Not sent = unchanged; empty = unlimited. */
    private static function optionalLimit(array $data, string $key, string $what, ?int $current, int $minimum = 1): ?int
    {
        if (! array_key_exists($key, $data)) {
            return $current;
        }
        $value = $data[$key];

        return $value === null || $value === '' ? null
            : Input::integer($data, $key, false, $minimum, "Enter at least {$minimum} {$what}, or leave it empty for unlimited.");
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
