<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Text;
use Illuminate\Support\Facades\DB;

/** Counter sales from the till. */
class Sales
{
    public const CREDIT_TERM_DAYS = 30;

    public static function counterSale(Store $store, User $user, mixed $items, mixed $method, mixed $customerId = null): Sale
    {
        if (! in_array($method, Sale::PAYMENT_METHODS, true)) {
            throw ApiError::invalid('Choose how the customer paid.', 'method');
        }
        if (! is_array($items) || ! $items) {
            throw ApiError::invalid('Add at least one item.');
        }
        $lines = [];
        $products = [];
        foreach ($items as $item) {
            $product = Product::find((string) ($item['productId'] ?? ''));
            if (! $product || $product->store_id !== $store->id) {
                throw ApiError::invalid("One of the items isn't in this store's products.");
            }
            $qty = is_numeric($item['qty'] ?? null) ? (int) $item['qty'] : 0;
            if ($qty < 1) {
                throw ApiError::invalid("Enter a quantity for {$product->name}.", 'qty');
            }
            $lines[] = ['product_id' => $product->id, 'name' => $product->name, 'unit' => $product->unit, 'qty' => $qty,
                'price' => $product->sell_price, 'cost' => $product->buy_price];
            $products[] = $product;
        }
        $total = array_sum(array_map(fn ($l) => $l['qty'] * $l['price'], $lines));

        $customer = null;
        if ($customerId) {
            $customer = Customer::find((string) $customerId);
            if (! $customer || $customer->store_id !== $store->id) {
                throw ApiError::invalid('Pick a customer from this store.', 'customer');
            }
        }
        if ($method === 'credit') {
            if (! $customer) {
                throw ApiError::invalid('Pick the customer this credit sale is for.', 'customer');
            }
            if ($customer->balance + $total > $customer->credit_limit) {
                throw ApiError::invalid("{$customer->name} would go over their credit limit.", 'customer');
            }
        }

        return DB::transaction(function () use ($store, $user, $method, $customer, $lines, $products, $total) {
            if ($method === 'credit') {
                $customer->balance += $total;
                $customer->due_at ??= now()->addDays(self::CREDIT_TERM_DAYS);
                $customer->save();
            }
            $sale = Sale::create(['store_id' => $store->id, 'channel' => Sale::POS, 'status' => Sale::COMPLETED, 'method' => $method,
                'total' => $total, 'staff_id' => $user->id, 'staff_name' => $user->name, 'customer_id' => $customer?->id,
                'customer_name' => $customer?->name, 'paid_at' => now()]);
            foreach ($lines as $i => $line) {
                $sale->items()->create($line);
                Stock::adjust($products[$i], -$line['qty'], 'sale', $sale->code, $user->id);
            }
            $items = "{$lines[0]['qty']} × {$lines[0]['name']}".(count($lines) > 1 ? ' and '.(count($lines) - 1).' more' : '');
            Activity::record('counter_sale', "Counter sale {$sale->code}: {$items} — ".Text::kes($total)." ({$method})", $store->id, $user,
                ['sale' => $sale->code, 'total' => $total, 'method' => $method]);

            return $sale->load('items');
        });
    }
}
