<?php

namespace App\Services;

use App\Models\Store;
use App\Support\ApiError;

/**
 * Delivery fees. Each shop chooses:
 *  - "zones": areas with a fixed fee each (e.g. Kitengela town KSh 300). The customer picks one at
 *    checkout and the fee is added to the M-PESA prompt. Optional free delivery above an order value.
 *  - "quote": for bulky or far orders; the shop phones to agree the cost (paid on delivery).
 * Pickup from the shop is always free.
 */
class Delivery
{
    public const MAX_ZONES = 20;
    public const MODES = [Store::DELIVERY_ZONES, Store::DELIVERY_QUOTE];

    /** [type, zone name, fee] for an order, checking the customer's choice. */
    public static function quote(Store $store, ?array $delivery, int $subtotal): array
    {
        $delivery ??= [];
        if (($delivery['type'] ?? null) !== 'delivery') {
            return ['pickup', null, 0];
        }
        if (! $store->delivery_enabled) {
            throw ApiError::invalid("{$store->name} doesn't deliver. Choose pickup.", 'deliveryType');
        }
        if (trim((string) ($delivery['address'] ?? '')) === '') {
            throw ApiError::invalid('Enter where to deliver.', 'address');
        }
        if ($store->delivery_mode === Store::DELIVERY_QUOTE) {
            return ['delivery', 'Agreed with the shop', 0];
        }
        $zone = collect($store->delivery_zones ?? [])->first(fn ($z) => ($z['id'] ?? null) === ($delivery['zoneId'] ?? null));
        if (! $zone) {
            throw ApiError::invalid('Choose your delivery area.', 'zoneId');
        }
        $fee = ($store->delivery_free_over && $subtotal >= $store->delivery_free_over) ? 0 : (int) $zone['fee'];

        return ['delivery', $zone['name'], $fee];
    }

    /** Check zones from the settings form: [{"name": "...", "fee": 300}, ...]. */
    public static function cleanZones(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw ApiError::invalid('Delivery areas must be a list.', 'deliveryZones');
        }
        $zones = [];
        foreach (array_slice($raw, 0, self::MAX_ZONES) as $z) {
            $name = mb_substr(trim((string) ($z['name'] ?? '')), 0, 80);
            if ($name === '') {
                continue;
            }
            $fee = $z['fee'] ?? 0;
            if ($fee !== null && $fee !== '' && ! is_numeric($fee)) {
                throw ApiError::invalid("Enter a fee in KSh for {$name}.", 'deliveryZones');
            }
            $zones[] = ['id' => $z['id'] ?? ('z'.bin2hex(random_bytes(3))), 'name' => $name, 'fee' => max(0, (int) $fee)];
        }

        return $zones;
    }
}
