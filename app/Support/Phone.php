<?php

namespace App\Support;

class Phone
{
    /** '0712 345 678' / '+254712345678' / '712345678' -> '254712345678', or null if not a Kenyan mobile. */
    public static function normalize(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($value ?? ''));
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '254'.substr($digits, 1);
        } elseif (strlen($digits) === 9 && in_array($digits[0], ['1', '7'], true)) {
            $digits = '254'.$digits;
        }

        return preg_match('/^254(7|1)\d{8}$/', $digits) ? $digits : null;
    }

    /** '254712345678' -> '0712 345 678' for messages and the website. */
    public static function display(?string $normalized): string
    {
        if (! $normalized || strlen($normalized) !== 12) {
            return $normalized ?? '';
        }
        $local = '0'.substr($normalized, 3);

        return substr($local, 0, 4).' '.substr($local, 4, 3).' '.substr($local, 7);
    }
}
