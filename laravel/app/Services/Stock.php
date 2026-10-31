<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use App\Support\ApiError;
use Illuminate\Support\Facades\DB;

/**
 * Stock levels, with a log of every change.
 *
 * reserve() is how two customers buying the last items at the same moment is handled:
 * one conditional UPDATE ... WHERE stock >= qty. The database lets exactly one of them
 * through; the other gets "Only N left" or "just sold out". Never check-then-save in PHP.
 */
class Stock
{
    /** Counter sales and manual changes. Counter sales may take stock below zero (goods are physically there). */
    public static function adjust(Product $product, int $delta, string $reason, string $reference = '', ?string $userId = null): Product
    {
        if ($delta === 0) {
            return $product;
        }
        Product::whereKey($product->id)->increment('stock', $delta);
        $product->stock += $delta;
        self::log($product, $delta, $reason, $reference, $userId);

        return $product;
    }

    /** Take stock for an online order, atomically. Throws 409 with how many are left. */
    public static function reserve(Product $product, int $qty, string $reference): void
    {
        $taken = DB::table('products')->where('id', $product->id)->where('stock', '>=', $qty)
            ->update(['stock' => DB::raw('stock - '.(int) $qty)]);
        if ($taken === 0) {
            $left = max((int) DB::table('products')->where('id', $product->id)->value('stock'), 0);
            throw new ApiError($left === 0
                ? "{$product->name} just sold out."
                : "Only {$left} left of {$product->name}. Lower the quantity and try again.", 409, 'qty');
        }
        $product->refresh();
        self::log($product, -$qty, 'online_order', $reference);
    }

    /** Put held stock back (unpaid, declined or cancelled order). */
    public static function release(Product $product, int $qty, string $reference, string $reason = 'released'): void
    {
        DB::table('products')->where('id', $product->id)->update(['stock' => DB::raw('stock + '.(int) $qty)]);
        self::log($product, $qty, $reason, $reference);
    }

    private static function log(Product $product, int $delta, string $reason, string $reference, ?string $userId = null): void
    {
        StockMovement::create(['store_id' => $product->store_id, 'product_id' => $product->id, 'delta' => $delta,
            'reason' => $reason, 'reference' => $reference, 'user_id' => $userId]);
    }
}
