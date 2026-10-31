<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Support\ApiError;

/**
 * Editable content for the main Topline site, managed from the Topline admin.
 * Image values are a picture key the website knows (e.g. "heroShelves") or an upload URL.
 */
class Site
{
    public const DEFAULTS = [
        'heroTitle' => 'Your hardware shop, online, with its own link.',
        'heroText' => 'Customers browse your cement, steel and mabati and pay by M-PESA. You run the counter, stock and credit from the same place.',
        'heroImage' => 'heroShelves',
        'featureImageStore' => 'storefront',
        'featureImageTill' => 'toolWall',
        'featureImageStock' => 'aisle',
        'signupImage' => 'ownerPhone',
        'announcement' => '',
        'supportPhone' => '0700 000 000',
        'supportWhatsapp' => '254700000000',
        'supportEmail' => 'hello@toplinehub.co.ke',
    ];

    private const MAX_LENGTH = ['heroTitle' => 120, 'heroText' => 300, 'announcement' => 200];

    public static function all(): array
    {
        $saved = SiteSetting::whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key')->all();

        return array_merge(self::DEFAULTS, $saved);
    }

    public static function update(array $data): array
    {
        $unknown = array_diff(array_keys($data), array_keys(self::DEFAULTS));
        if ($unknown) {
            sort($unknown);
            throw ApiError::invalid('Unknown setting: '.implode(', ', $unknown).'.');
        }
        foreach ($data as $key => $value) {
            $value = $value === null ? '' : trim((string) $value);
            $max = self::MAX_LENGTH[$key] ?? 500;
            if (mb_strlen($value) > $max) {
                throw ApiError::invalid("Keep this under {$max} characters.", $key);
            }
            SiteSetting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_at' => now()]);
        }

        return self::all();
    }
}
