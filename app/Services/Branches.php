<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Ctx;
use App\Support\Input;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A shop's branches (up to the plan's limit). Each branch has its own stock and sales.
 * The main branch's stock is products.stock (the online store sells from it); other branches'
 * stock is in branch_stock. Staff can be tied to one branch.
 */
class Branches
{
    /** The store's main branch, created the first time it's needed. */
    public static function main(Store $store): Branch
    {
        return Branch::where('store_id', $store->id)->where('is_main', true)->first()
            ?? Branch::create(['store_id' => $store->id, 'name' => 'Main shop', 'location' => $store->town ?? '', 'is_main' => true]);
    }

    /** @return Collection<int, Branch> main branch first */
    public static function all(Store $store): Collection
    {
        self::main($store);

        return Branch::where('store_id', $store->id)->orderByDesc('is_main')->orderBy('created_at')->orderBy('name')->get();
    }

    /** null/'' → the main branch; otherwise one of this store's branches (404 if not). */
    public static function find(Store $store, mixed $branchId): Branch
    {
        if ($branchId === null || $branchId === '') {
            return self::main($store);
        }
        $branch = is_string($branchId) ? Branch::find($branchId) : null;
        if (! $branch || $branch->store_id !== $store->id) {
            throw ApiError::notFound('That branch doesn’t exist.');
        }

        return $branch;
    }

    /**
     * The branch the caller is working at: staff tied to a branch always get theirs;
     * owners, unassigned staff and Topline admins get the one they asked for.
     */
    public static function forCaller(Store $store, mixed $requested): Branch
    {
        $user = Access::currentUser();
        if (! $user->isPlatformAdmin()) {
            $assigned = StoreMember::where('store_id', $store->id)->where('user_id', $user->id)->value('branch_id');
            if ($assigned) {
                return self::find($store, $assigned);
            }
        }

        return self::find($store, $requested);
    }

    public static function create(Store $store, array $data, User $by): Branch
    {
        Plans::checkLimit($store, 'branches', self::all($store)->count(), 'branches', 'name');
        $branch = Branch::create(['store_id' => $store->id, ...self::fields($store, $data)]);
        Activity::record('branch_added', "{$by->name} added the branch {$branch->name}", $store->id, $by);

        return $branch;
    }

    public static function update(Store $store, Branch $branch, array $data): Branch
    {
        $branch->fill(self::fields($store, $data, $branch))->save();

        return $branch;
    }

    /** Only an empty branch can go: no stock left and no sales. Otherwise rename it. */
    public static function delete(Store $store, Branch $branch, User $by): void
    {
        if ($branch->is_main) {
            throw ApiError::invalid('The main branch can’t be removed. You can rename it.');
        }
        if (Sale::where('branch_id', $branch->id)->exists()) {
            throw ApiError::invalid('This branch has sales on record, so it can’t be removed. Rename it instead.');
        }
        if (DB::table('branch_stock')->where('branch_id', $branch->id)->where('qty', '!=', 0)->exists()) {
            throw ApiError::invalid('Move or clear this branch’s stock first.');
        }
        DB::transaction(function () use ($branch) {
            StoreMember::where('branch_id', $branch->id)->update(['branch_id' => null]);
            $branch->delete();
        });
        Activity::record('branch_removed', "{$by->name} removed the branch {$branch->name}", $store->id, $by);
    }

    private static function fields(Store $store, array $data, ?Branch $branch = null): array
    {
        $name = Input::text($data, 'name', true, 80, 'Give the branch a name.');
        $taken = Branch::where('store_id', $store->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($branch, fn ($q) => $q->where('id', '!=', $branch->id))->exists();
        if ($taken) {
            throw ApiError::invalid('You already have a branch with that name.', 'name');
        }

        return ['name' => $name, 'location' => Input::text($data, 'location', false, 160)];
    }

    // ---------------------------------------------------------------- stock per branch

    /** productId => qty at $branch, for every product of the store. */
    public static function stockMap(Store $store, Branch $branch): array
    {
        if ($branch->is_main) {
            return Product::where('store_id', $store->id)->pluck('stock', 'id')->map(fn ($q) => (int) $q)->all();
        }

        return DB::table('branch_stock')->where('branch_id', $branch->id)->pluck('qty', 'product_id')->map(fn ($q) => (int) $q)->all();
    }

    public static function stockAt(Product $product, Branch $branch): int
    {
        return $branch->is_main ? (int) $product->stock
            : (int) DB::table('branch_stock')->where('branch_id', $branch->id)->where('product_id', $product->id)->value('qty');
    }

    /** branchId => [productId => qty] for every branch: owners see stock across branches. */
    public static function stockByBranch(Store $store): array
    {
        $out = [];
        foreach (self::all($store) as $branch) {
            $out[$branch->id] = self::stockMap($store, $branch);
        }

        return $out;
    }

    public static function json(Branch $b, array $staffCounts = []): array
    {
        return ['id' => $b->id, 'name' => $b->name, 'location' => $b->location, 'isMain' => $b->is_main, 'staff' => $staffCounts[$b->id] ?? 0];
    }

    /** For the shop admin: the branches, which one the caller is tied to, and the plan's limit. */
    public static function summary(Store $store): array
    {
        $staff = StoreMember::where('store_id', $store->id)->whereNotNull('branch_id')
            ->selectRaw('branch_id, COUNT(*) as n')->groupBy('branch_id')->pluck('n', 'branch_id')->all();
        $membership = Ctx::storeRole() ? StoreMember::where('store_id', $store->id)->where('user_id', Access::currentUser()->id)->first() : null;

        return [
            'branches' => self::all($store)->map(fn ($b) => self::json($b, $staff))->values()->all(),
            'myBranchId' => $membership?->branch_id,
        ];
    }
}
