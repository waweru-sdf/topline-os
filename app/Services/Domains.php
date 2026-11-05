<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use App\Support\ApiError;
use Illuminate\Support\Facades\Cache;

/**
 * A shop's own address, on plans that include it: either a Topline subdomain named after the shop
 * (kamandes.toplinehub.co.ke) or a domain the shop owns (kamandeshardware.co.ke).
 * The owner picks it; a Topline admin adds it to the hosting (hPanel: a subdomain, or a parked domain)
 * and marks it connected; from then on that address opens the shop. Same app, same server.
 * (Shared hosting can't serve every subdomain automatically with https, so each is added by hand.)
 */
class Domains
{
    public const PENDING = 'pending';
    public const CONNECTED = 'connected';

    private const CACHE_PREFIX = 'domain:';

    public static function set(Store $store, mixed $domain, User $by): Store
    {
        $domain = self::normalize($domain);
        $root = self::root();
        if ($domain && $root && str_ends_with($domain, ".{$root}") && $domain !== "{$store->slug}.{$root}") {
            throw ApiError::invalid("Your Topline address can only be {$store->slug}.{$root}.", 'domain');
        }
        if ($domain !== null && ! Plans::limit($store, 'customDomain')) {
            throw ApiError::invalid('Your plan doesn’t include your own domain. Change plan under Plan & billing.', 'domain');
        }
        if ($domain === $store->custom_domain) {
            return $store;
        }
        if ($domain && Store::where('custom_domain', $domain)->where('id', '!=', $store->id)->exists()) {
            throw ApiError::invalid('Another shop on Topline already uses that domain.', 'domain');
        }
        $old = $store->custom_domain;
        $store->update(['custom_domain' => $domain, 'domain_status' => $domain ? self::PENDING : '']);
        self::forget($old);
        Activity::record('domain_changed', $domain ? "{$by->name} asked to connect {$domain}" : "{$by->name} removed the domain {$old}", $store->id, $by);

        return $store;
    }

    /** Topline admin, after adding the domain in hPanel. */
    public static function setStatus(Store $store, string $status, User $admin): Store
    {
        if (! $store->custom_domain || ! in_array($status, [self::PENDING, self::CONNECTED], true)) {
            throw ApiError::invalid('This shop has no domain to connect.', 'domainStatus');
        }
        $store->update(['domain_status' => $status]);
        self::forget($store->custom_domain);
        Activity::record('domain_changed', "{$admin->name} marked {$store->custom_domain} as {$status}", $store->id, $admin);

        return $store;
    }

    /** The shop a visitor reached by its own domain: connected, plan still includes it, shop open to customers. */
    public static function resolve(string $host): ?string
    {
        $host = self::normalize($host, false);
        if (! $host) {
            return null;
        }

        // Only hits are cached: caching every unknown host anyone sends would fill the disk.
        if ($slug = Cache::get(self::CACHE_PREFIX.$host)) {
            return $slug;
        }
        $bare = preg_replace('/^www\./', '', $host);
        $store = Store::with('plan')->whereIn('custom_domain', array_unique([$host, $bare]))->where('domain_status', self::CONNECTED)->first();
        if (! $store || ! Plans::limit($store, 'customDomain') || $store->status === Store::DRAFT) {
            return null;
        }
        Cache::put(self::CACHE_PREFIX.$host, $store->slug, 600);

        return $store->slug;
    }

    public static function pendingCount(): int
    {
        return Store::whereNotNull('custom_domain')->where('domain_status', self::PENDING)->count();
    }

    private static function root(): string
    {
        return strtolower((string) config('topline.root_domain'));
    }

    private static function forget(?string $domain): void
    {
        if ($domain) {
            Cache::forget(self::CACHE_PREFIX.$domain);
            Cache::forget(self::CACHE_PREFIX.'www.'.$domain);
        }
    }

    /** "https://www.Shop.co.ke/" → "shop.co.ke". Empty → null. Rejects Topline's own addresses. */
    private static function normalize(mixed $value, bool $strict = true): ?string
    {
        $domain = strtolower(trim((string) ($value ?? '')));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim(explode('/', $domain)[0], '.');
        if ($strict) {
            $domain = preg_replace('/^www\./', '', $domain);
        }
        if ($domain === '') {
            return null;
        }
        $valid = strlen($domain) <= 120 && preg_match('/^(?=.{4,120}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain);
        $root = self::root();
        $reserved = $root && ($domain === $root || in_array($domain, array_map(fn ($s) => "{$s}.{$root}", ['www', 'app', 'admin', 'api', 'mail']), true)
            || substr_count(substr($domain, 0, -strlen($root) - 1), '.') > 0 && str_ends_with($domain, ".{$root}")); // only one level: name.root
        if (! $valid || $reserved) {
            if ($strict) {
                throw ApiError::invalid('Enter a domain like yourshop.co.ke', 'domain');
            }

            return null;
        }

        return $domain;
    }
}
