<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Ctx;
use Illuminate\Support\Facades\Auth;

/**
 * Who is calling, and may they open this store?
 *  - currentUser(): the user behind "Authorization: Bearer <token>" (Laravel Sanctum).
 *  - loadStore(): shop owners/staff get their own store only; Topline admins get every store.
 */
class Access
{
    /** While a temporary password is in use, only these routes work. */
    public const PASSWORD_CHANGE_ROUTES = ['auth.me', 'auth.password'];

    public static function currentUser(bool $optional = false): ?User
    {
        $user = Ctx::user();
        if (! $user) {
            Auth::forgetGuards(); // never reuse a user resolved for an earlier request (long-running workers, tests)
            /** @var User|null $user */
            $user = Auth::guard('sanctum')->user();
            if ($user && ! $user->is_active) {
                $user = null;
            }
            Ctx::setUser($user);
        }
        if (! $user) {
            if ($optional) {
                return null;
            }
            throw ApiError::unauthorized('Your session has ended. Log in again.');
        }
        if ($user->must_change_password && ! in_array(request()->route()?->getName(), self::PASSWORD_CHANGE_ROUTES, true)) {
            throw new ApiError('Choose a new password first.', 403, 'newPassword');
        }

        return $user;
    }

    public static function loadStore(?string $storeId): Store
    {
        $user = self::currentUser();
        $store = $storeId ? Store::find($storeId) : null;
        if (! $store) {
            throw ApiError::notFound("That store doesn't exist.");
        }
        $membership = null;
        if (! $user->isPlatformAdmin()) {
            $membership = StoreMember::where('store_id', $store->id)->where('user_id', $user->id)->first();
            if (! $membership) {
                // Same answer as "not found", so store ids can't be probed.
                throw ApiError::notFound("That store doesn't exist.");
            }
        }
        Ctx::setStore($store, $membership?->role, Permissions::for($membership?->role, $user->isPlatformAdmin()));

        return $store;
    }
}
