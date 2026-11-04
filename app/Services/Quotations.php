<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Quote;
use App\Models\Store;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Input;
use App\Support\Phone;
use App\Support\Text;

/**
 * Quotations that turn into sales. Customers ask for one on the store page (free text); the shop
 * prices it at the till ("Save as quotation"), shares it on WhatsApp, and later turns it into a
 * sale, which marks the quotation won.
 */
class Quotations
{
    public static function find(Store $store, mixed $quoteId): Quote
    {
        $quote = is_string($quoteId) ? Quote::find($quoteId) : null;
        if (! $quote || $quote->store_id !== $store->id) {
            throw ApiError::notFound('Quotation not found.');
        }

        return $quote;
    }

    /** From the till: items at today's prices. With quoteId, prices a customer's request; otherwise a new quotation. */
    public static function save(Store $store, array $data, User $by): Quote
    {
        $items = $data['items'] ?? null;
        if (! is_array($items) || ! $items || count($items) > 100) {
            throw ApiError::invalid('Add at least one item.');
        }
        $lines = [];
        foreach ($items as $item) {
            $product = Product::find((string) ($item['productId'] ?? ''));
            if (! $product || $product->store_id !== $store->id) {
                throw ApiError::invalid("One of the items isn't in this store's products.");
            }
            $qty = is_numeric($item['qty'] ?? null) ? (int) $item['qty'] : 0;
            if ($qty < 1) {
                throw ApiError::invalid("Enter a quantity for {$product->name}.", 'qty');
            }
            $lines[] = ['productId' => $product->id, 'name' => $product->name, 'unit' => $product->unit, 'qty' => $qty, 'price' => $product->sell_price];
        }
        $total = array_sum(array_map(fn ($l) => $l['qty'] * $l['price'], $lines));

        $quote = ($data['quoteId'] ?? null) ? self::find($store, $data['quoteId']) : null;
        if ($quote?->status === 'won') {
            throw ApiError::invalid('This quotation already became a sale.');
        }
        if (! $quote) {
            $phone = trim((string) ($data['phone'] ?? ''));
            $phone = $phone === '' ? '' : (Phone::normalize($phone) ?? throw ApiError::invalid('Enter a number like 0712 345 678, or leave it empty.', 'phone'));
            $quote = new Quote(['store_id' => $store->id, 'reference' => 'Q-'.random_int(10000, 99999), 'source' => 'shop',
                'name' => Input::text($data, 'name', true, 120, 'Enter who the quotation is for.'), 'phone' => $phone,
                'items' => '', 'site' => '']);
        }
        $quote->fill(['lines' => $lines, 'total' => $total, 'status' => 'replied'])->save();
        Activity::record('quotation', "{$by->name} priced quotation {$quote->reference} for {$quote->name}: ".Text::kes($total), $store->id, $by);

        return $quote;
    }
}
