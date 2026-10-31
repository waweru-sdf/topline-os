<?php

namespace App\Support;

use App\Models\Store;
use App\Models\User;

/**
 * What the current request is about: who is calling, and (inside the shop admin) which store
 * and with which permissions. Kept on the Request, so nothing leaks between requests.
 */
class Ctx
{
    public static function user(): ?User
    {
        return request()->attributes->get('topline.user');
    }

    public static function setUser(?User $user): void
    {
        request()->attributes->set('topline.user', $user);
    }

    public static function store(): ?Store
    {
        return request()->attributes->get('topline.store');
    }

    public static function storeRole(): ?string
    {
        return request()->attributes->get('topline.store_role');
    }

    public static function permissions(): array
    {
        return request()->attributes->get('topline.permissions', []);
    }

    public static function setStore(Store $store, ?string $role, array $permissions): void
    {
        request()->attributes->set('topline.store', $store);
        request()->attributes->set('topline.store_role', $role);
        request()->attributes->set('topline.permissions', $permissions);
    }

    public static function can(string $permission): bool
    {
        return in_array($permission, self::permissions(), true);
    }

    public static function require(string $permission): void
    {
        if (! self::can($permission)) {
            throw ApiError::forbidden("Your role in this store doesn't allow this. Ask the shop owner.");
        }
    }
}
