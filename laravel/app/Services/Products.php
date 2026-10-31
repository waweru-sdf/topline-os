<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Input;
use App\Support\Text;
use Illuminate\Support\Facades\DB;

class Products
{
    public static function find(Store $store, mixed $productId): Product
    {
        $product = is_string($productId) ? Product::find($productId) : null;
        if (! $product || $product->store_id !== $store->id) {
            throw ApiError::notFound('That product was deleted or never existed.');
        }

        return $product;
    }

    /** Create ($product = null) or update a product from the editor form. */
    public static function save(Store $store, array $data, User $user, ?Product $product = null): Product
    {
        $name = Input::text($data, 'name', required: true, maxLength: 160, message: 'Give the product a name.');
        $sellPrice = Input::integer($data, 'sellPrice', required: true, minimum: 1, message: 'Enter a selling price above 0.');
        $categoryId = ($data['categoryId'] ?? null) ?: 'tools';
        if (! in_array($categoryId, Catalog::ids(), true)) {
            throw ApiError::invalid('Pick a category.', 'categoryId');
        }
        $vatClass = ($data['vatClass'] ?? null) ?: 'standard';
        if (! in_array($vatClass, Product::VAT_CLASSES, true)) {
            throw ApiError::invalid('Pick a VAT class.', 'vatClass');
        }
        $gallery = array_key_exists('gallery', $data) ? self::cleanGallery($data['gallery']) : null;
        $specs = array_key_exists('specs', $data) ? self::cleanSpecs($data['specs']) : null;

        return DB::transaction(function () use ($store, $data, $user, $product, $name, $sellPrice, $categoryId, $vatClass, $gallery, $specs) {
            $isNew = $product === null;
            if ($isNew) {
                self::checkPlanLimit($store);
                $product = new Product(['store_id' => $store->id, 'slug' => self::uniqueSlug($store, $name), 'stock' => 0]);
            }
            $oldStock = $isNew ? 0 : $product->stock;
            $product->fill([
                'name' => $name,
                'category_id' => $categoryId,
                'unit' => Input::text($data, 'unit', maxLength: 40, default: 'piece'),
                'sell_price' => $sellPrice,
                'buy_price' => Input::integer($data, 'buyPrice', minimum: 0),
                'reorder_at' => Input::integer($data, 'reorderAt', minimum: 0, default: 10),
                'description' => Input::text($data, 'description', maxLength: 500),
                'image' => Input::text($data, 'image', maxLength: 300), // upload URL, ready-made picture key, or empty
                'size' => Input::text($data, 'size', maxLength: 80),
                'vat_class' => $vatClass,
                'published' => (bool) ($data['published'] ?? true),
            ]);
            if ($gallery !== null) {
                $product->gallery = $gallery;
            }
            if ($specs !== null) {
                $product->specs = $specs;
            }
            if ($isNew) {
                $product->gallery ??= [];
                $product->specs ??= [];
                $product->alt_units = [];
            }
            if (is_array($data['altUnits'] ?? null)) {
                $product->alt_units = array_slice($data['altUnits'], 0, 10);
            }
            $product->save();

            // Stock typed into the form is recorded as an adjustment, so history stays complete.
            $newStock = Input::integer($data, 'stock', default: $oldStock);
            Stock::adjust($product, $newStock - $oldStock, 'adjust', 'product form', $user->id);
            $size = $product->size ? " ({$product->size})" : '';
            if ($isNew) {
                Activity::record('product_added', "Added {$product->name}{$size}, {$newStock} {$product->unit} in stock", $store->id, $user, ['productId' => $product->id]);
            } elseif ($newStock !== $oldStock) {
                Activity::record('stock_adjusted', "Stock of {$product->name} changed {$oldStock} → {$newStock}", $store->id, $user,
                    ['productId' => $product->id, 'before' => $oldStock, 'after' => $newStock]);
            } else {
                Activity::record('product_updated', "Edited {$product->name}", $store->id, $user, ['productId' => $product->id]);
            }

            return $product->refresh();
        });
    }

    public static function delete(Store $store, mixed $productId): void
    {
        $product = self::find($store, $productId);
        DB::transaction(function () use ($store, $product) {
            Activity::record('product_deleted', "Deleted {$product->name}", $store->id);
            $product->delete(); // past sales keep their copied name and price
        });
    }

    private static function cleanGallery(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw ApiError::invalid('Photos must be a list.', 'gallery');
        }
        $photos = array_values(array_filter(array_map(fn ($p) => mb_substr(trim((string) $p), 0, 300), $raw), fn ($p) => $p !== ''));
        if (count($photos) > 3) {
            throw ApiError::invalid('Add up to 3 extra photos.', 'gallery');
        }

        return $photos;
    }

    /** [{"label": "Grade", "value": "Y12"}]: blank rows dropped, half-filled rows refused, up to 8. */
    private static function cleanSpecs(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw ApiError::invalid('Measurements must be a list.', 'specs');
        }
        $specs = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = mb_substr(trim((string) ($row['label'] ?? '')), 0, 40);
            $value = mb_substr(trim((string) ($row['value'] ?? '')), 0, 80);
            if ($label !== '' && $value !== '') {
                $specs[] = ['label' => $label, 'value' => $value];
            } elseif ($label !== '' || $value !== '') {
                throw ApiError::invalid('Fill in both the name and the value of each measurement, or clear the row.', 'specs');
            }
        }
        if (count($specs) > 8) {
            throw ApiError::invalid('Add up to 8 measurements.', 'specs');
        }

        return $specs;
    }

    private static function uniqueSlug(Store $store, string $name): string
    {
        $base = Text::slugify($name) ?: 'product';
        $slug = $base;
        for ($n = 2; Product::where('store_id', $store->id)->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private static function checkPlanLimit(Store $store): void
    {
        $limit = $store->plan?->limits['products'] ?? null;
        if ($limit === null) {
            return;
        }
        if (Product::where('store_id', $store->id)->count() >= $limit) {
            throw new ApiError("Your {$store->plan->name} plan allows {$limit} products. Upgrade to add more.", 402);
        }
    }
}
