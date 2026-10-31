<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

class Text
{
    public const RESERVED_SLUGS = ['www', 'app', 'admin', 'api', 'mail', 'static', 'dashboard', 'login', 'start', 's', 'help', 'support'];

    /** 'Mjengo Hardware & Supplies' -> 'mjengo-hardware-supplies' (same rules as the website). */
    public static function slugify(mixed $text, int $maxLength = 40): string
    {
        $text = (string) ($text ?? '');
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KD) ?: $text;
        }
        $text = preg_replace('/[^\x00-\x7F]/', '', $text);
        $text = preg_replace('/[^a-z0-9]+/', '-', str_replace('&', ' ', strtolower($text)));

        return trim(substr(trim($text, '-'), 0, $maxLength), '-');
    }

    /** Date -> '2026-10-29T08:00:00.000Z' (UTC), or null. */
    public static function iso(?DateTimeInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return Carbon::instance($date)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /** "KSh 1,900" */
    public static function kes(int|float $amount): string
    {
        return 'KSh '.number_format((int) $amount);
    }

    /** Short random ids such as "prd_k3J9xQ2mA1bc" (12 URL-safe characters). */
    public static function newId(string $prefix): string
    {
        return $prefix.'_'.rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '=');
    }

    /** Our payment references, e.g. "ORD_8F2K1A9C3D4E5F60". */
    public static function reference(string $prefix): string
    {
        return $prefix.'_'.strtoupper(bin2hex(random_bytes(8)));
    }
}
