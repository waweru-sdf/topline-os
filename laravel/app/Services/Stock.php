<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\ApiError;
use Illuminate\Support\Facades\DB;

/**
 * Stock levels, with a log of every change. Main-branch stock is products.stock; other branches'
 * stock is in branch_stock (see Branches). An online order takes stock from the branch whose store page it came from.
 *
 * reserve() is how two customers buying the last items at the same moment is handled:
 * one conditional UPDATE ... WHERE stock >= qty. The database lets exactly one of them
 * through; the other gets "Only N left" or "just sold out". Never check-then-save in PHP.
 */
class Stock
{
    /** Counter sales and manual changes. Counter sales may take stock below zero (goods are physically there). */
    public static function adjust(Product $product, int $delta, string $reason, string $reference = '', ?string $userId = null, ?Branch $branch = null): Product
    {
        if ($delta === 0) {
            return $product;
        }
        if (! $branch || $branch->is_main) {
            Product::whereKey($product->id)->increment('stock', $delta);
            $product->stock += $delta;
        } else {
            DB::table('branch_stock')->insertOrIgnore(['branch_id' => $branch->id, 'product_id' => $product->id, 'qty' => 0]);
            DB::table('branch_stock')->where('branch_id', $branch->id)->where('product_id', $product->id)->increment('qty', $delta);
        }
        self::log($product, $delta, $reason, $reference, $userId, $branch && ! $branch->is_main ? $branch->id : null);

        return $product;
    }

    /** Move stock from one branch to another. */
    public static function transfer(Product $product, Branch $from, Branch $to, int $qty, string $userId): void
    {
        if ($from->id === $to->id) {
            throw ApiError::invalid('Choose two different branches.', 'toBranchId');
        }
        if ($qty < 1) {
            throw ApiError::invalid('Enter how many to move.', 'qty');
        }
        DB::transaction(function () use ($product, $from, $to, $qty, $userId) {
            self::adjust($product, -$qty, 'transfer_out', "to {$to->name}", $userId, $from);
            self::adjust($product, $qty, 'transfer_in', "from {$from->name}", $userId, $to);
        });
    }

    /** Take stock for an online order, atomically. Throws 409 with how many are left. */
    public static function reserve(Product $product, int $qty, string $reference, ?Branch $branch = null): void
    {
        $atBranch = $branch && ! $branch->is_main;
        $row = $atBranch ? DB::table('branch_stock')->where('branch_id', $branch->id)->where('product_id', $product->id)
            : DB::table('products')->where('id', $product->id);
        $column = $atBranch ? 'qty' : 'stock';
        $taken = (clone $row)->where($column, '>=', $qty)->update([$column => DB::raw("{$column} - ".(int) $qty)]);
        if ($taken === 0) {
            $left = max((int) (clone $row)->value($column), 0);
            throw new ApiError($left === 0
                ? "{$product->name} just sold out."
                : "Only {$left} left of {$product->name}. Lower the quantity and try again.", 409, 'qty');
        }
        $product->refresh();
        self::log($product, -$qty, 'online_order', $reference, null, $atBranch ? $branch->id : null);
    }

    /** Put held stock back (unpaid, declined or cancelled order), at the branch it was taken from. */
    public static function release(Product $product, int $qty, string $reference, string $reason = 'released', ?Branch $branch = null): void
    {
        self::adjust($product, $qty, $reason, $reference, null, $branch);
    }

    private static function log(Product $product, int $delta, string $reason, string $reference, ?string $userId = null, ?string $branchId = null): void
    {
        StockMovement::create(['store_id' => $product->store_id, 'product_id' => $product->id, 'delta' => $delta,
            'reason' => $reason, 'reference' => mb_substr($reference, 0, 40), 'user_id' => $userId, 'branch_id' => $branchId]);
    }
}
