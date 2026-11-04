<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Phone;
use App\Support\Text;
use Illuminate\Support\Facades\DB;

/**
 * The shop's staff logins. The owner adds managers and cashiers (up to the plan's staff limit),
 * gives them a temporary password, and can tie them to one branch.
 *  - manager: everything except store settings, billing and staff
 *  - cashier: sells, takes orders and credit payments; never sees buying prices or profit
 */
class Staff
{
    public const ROLES = [StoreMember::MANAGER, StoreMember::CASHIER];

    public static function list(Store $store): array
    {
        return StoreMember::with('user')->where('store_id', $store->id)->orderBy('id')->get()
            ->map(fn (StoreMember $m) => self::json($m))->values()->all();
    }

    /** @return array{member: array, temporaryPassword: string} */
    public static function add(Store $store, array $data, User $by): array
    {
        $used = StoreMember::where('store_id', $store->id)->where('role', '!=', StoreMember::OWNER)->count();
        Plans::checkLimit($store, 'staff', $used, 'staff logins', 'phone');
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw ApiError::invalid('Enter their name.', 'name');
        }
        $phone = Phone::normalize($data['phone'] ?? null) ?? throw ApiError::invalid('Enter a mobile number like 0712 345 678.', 'phone');
        $role = self::role($data);
        $branchId = self::branchId($store, $data);

        $user = User::where('phone', $phone)->first();
        // A former staff member (switched off, no shop any more) can be added again; any other login can't.
        if ($user && ($user->is_active || $user->role !== User::VENDOR || $user->memberships()->exists())) {
            throw ApiError::invalid('That number already has a Topline login. Use another number.', 'phone');
        }
        $temp = Passwords::temporary();
        $member = DB::transaction(function () use ($user, $name, $phone, $temp, $store, $role, $branchId) {
            $user ??= new User(['phone' => $phone, 'role' => User::VENDOR]);
            $user->fill(['name' => $name, 'is_active' => true, 'must_change_password' => true]);
            Passwords::set($user, $temp);
            $user->save();

            return StoreMember::create(['store_id' => $store->id, 'user_id' => $user->id, 'role' => $role, 'branch_id' => $branchId]);
        });
        Activity::record('staff_added', "{$by->name} added {$name} as {$role}", $store->id, $by);

        return ['member' => self::json($member->load('user')), 'temporaryPassword' => $temp];
    }

    public static function update(Store $store, StoreMember $member, array $data, User $by): array
    {
        self::notOwner($member);
        $member->fill(['role' => self::role($data), 'branch_id' => self::branchId($store, $data)])->save();
        Activity::record('staff_changed', "{$by->name} changed {$member->user?->name} to {$member->role}", $store->id, $by);

        return self::json($member->load('user'));
    }

    /** Takes away their access to this shop. Their name stays on past sales. */
    public static function remove(Store $store, StoreMember $member, User $by): void
    {
        self::notOwner($member);
        $user = $member->user;
        DB::transaction(function () use ($member, $user) {
            $member->delete();
            if ($user && ! $user->memberships()->exists()) {
                $user->update(['is_active' => false]);
                $user->tokens()->delete(); // logged out everywhere at once
            }
        });
        Activity::record('staff_removed', "{$by->name} removed {$user?->name}", $store->id, $by);
    }

    public static function resetPassword(Store $store, StoreMember $member, User $by): array
    {
        self::notOwner($member);

        return ['temporaryPassword' => Passwords::adminReset($member->user, $by, $store->id), 'name' => $member->user->name,
            'login' => Phone::display($member->user->phone)];
    }

    public static function find(Store $store, mixed $memberId): StoreMember
    {
        $member = ctype_digit((string) $memberId) ? StoreMember::with('user')->find((int) $memberId) : null;
        if (! $member || $member->store_id !== $store->id) {
            throw ApiError::notFound('That person isn’t on your staff.');
        }

        return $member;
    }

    private static function notOwner(StoreMember $member): void
    {
        if ($member->role === StoreMember::OWNER) {
            throw ApiError::forbidden('The owner’s login can’t be changed here.');
        }
    }

    private static function role(array $data): string
    {
        $role = (string) ($data['role'] ?? '');
        if (! in_array($role, self::ROLES, true)) {
            throw ApiError::invalid('Choose manager or cashier.', 'role');
        }

        return $role;
    }

    /** '' or null = works at every branch. */
    private static function branchId(Store $store, array $data): ?string
    {
        $id = $data['branchId'] ?? null;

        return $id === null || $id === '' ? null : Branches::find($store, $id)->id;
    }

    public static function json(StoreMember $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->user?->name,
            'phone' => $m->user ? Phone::display($m->user->phone) : null,
            'role' => $m->role,
            'branchId' => $m->branch_id,
            'lastLoginAt' => Text::iso($m->user?->last_login_at),
            'mustChangePassword' => (bool) $m->user?->must_change_password,
        ];
    }
}
