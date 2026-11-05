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
use App\Support\Phone;
use App\Support\Text;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A shop's branches (up to the plan's limit). Each branch has its own stock, sales and store page:
 * the shop's address + /<branch slug> (e.g. mjengo.co.ke/kitsuru), with its own contacts, hours and
 * cover photo; the shop's address itself is the main branch's page. Products are shared.
 * The main branch's stock is products.stock; other branches' stock is in branch_stock.
 * Staff can be tied to one branch. One shop admin for all branches: pick the branch in the top bar.
 */
class Branches
{
    /** Store-page paths a branch can't be called. */
    private const RESERVED_SLUGS = ['p', 'cart', 'checkout'];

    /** The store's main branch, created the first time it's needed. */
    public static function main(Store $store): Branch
    {
        return Branch::where('store_id', $store->id)->where('is_main', true)->first()
            ?? Branch::create(['store_id' => $store->id, 'name' => 'Main shop', 'slug' => 'main-shop', 'location' => $store->town ?? '', 'is_main' => true]);
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
        $fields = self::fields($store, $data);
        $branch = Branch::create(['store_id' => $store->id, ...$fields, 'slug' => self::uniqueSlug($store, $fields['name'])]);
        Activity::record('branch_added', "{$by->name} added the branch {$branch->name}", $store->id, $by);

        return $branch;
    }

    public static function update(Store $store, Branch $branch, array $data): Branch
    {
        $current = ['name' => $branch->name, 'location' => $branch->location, 'phone' => $branch->phone, 'whatsapp' => $branch->whatsapp,
            'hours' => $branch->hours, 'cover' => $branch->cover]; // fields not sent stay as they are
        $branch->fill(self::fields($store, [...$current, ...$data], $branch))->save();

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

        $phone = trim((string) ($data['phone'] ?? ''));
        $phone = $phone === '' ? '' : Phone::display(Phone::normalize($phone) ?? throw ApiError::invalid('Enter a number like 0712 345 678, or leave it empty.', 'phone'));
        $whatsapp = trim((string) ($data['whatsapp'] ?? ''));
        $whatsapp = $whatsapp === '' ? '' : (Phone::normalize($whatsapp) ?? throw ApiError::invalid('Enter a number like 0712 345 678, or leave it empty.', 'whatsapp'));
        $cover = Input::text($data, 'cover', false, 300);
        if ($cover !== '' && ! preg_match('#^https?://[^\s<>"]+$#i', $cover)) {
            throw ApiError::invalid('Upload the photo again.', 'cover');
        }

        return ['name' => $name, 'location' => Input::text($data, 'location', false, 160), 'phone' => $phone, 'whatsapp' => $whatsapp,
            'hours' => Input::text($data, 'hours', false, 120), 'cover' => $cover];
    }

    /** Set once, when the branch is added, so links already shared keep working after a rename. */
    private static function uniqueSlug(Store $store, string $name): string
    {
        $base = Text::slugify($name, 50) ?: 'branch';
        if (in_array($base, self::RESERVED_SLUGS, true)) {
            $base .= '-branch';
        }
        $slug = $base;
        for ($i = 2; Branch::where('store_id', $store->id)->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /** The branch whose store page this is: ?branch=<slug>; empty = the main branch. */
    public static function forPage(Store $store, mixed $slug): Branch
    {
        if ($slug === null || $slug === '') {
            return self::main($store);
        }
        $branch = is_string($slug) ? Branch::where('store_id', $store->id)->where('slug', $slug)->first() : null;

        return $branch ?? throw ApiError::notFound("{$store->name} has no branch called \"{$slug}\".");
    }

    /** For the store page: every branch, so customers can switch. Empty when there's only one. */
    public static function publicList(Store $store): array
    {
        $all = self::all($store);

        return $all->count() < 2 ? [] : $all->map(fn ($b) => ['slug' => $b->is_main ? null : $b->slug, 'name' => $b->name, 'location' => $b->location])->values()->all();
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
        return ['id' => $b->id, 'slug' => $b->slug, 'name' => $b->name, 'location' => $b->location, 'isMain' => $b->is_main,
            'phone' => $b->phone, 'whatsapp' => $b->whatsapp, 'hours' => $b->hours, 'cover' => $b->cover ?: null, 'staff' => $staffCounts[$b->id] ?? 0];
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
