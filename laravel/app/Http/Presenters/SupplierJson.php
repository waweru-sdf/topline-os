<?php

namespace App\Http\Presenters;

use App\Models\CreditRequest;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierProduct;
use App\Services\Suppliers;
use App\Support\Phone;
use App\Support\Text;

/**
 * JSON for suppliers, supplier orders, credit requests and expenses.
 * Each audience gets only what it should see:
 *  - publicCard: the landing page (no phone, email, prices or payment details)
 *  - forShop:    logged-in shop staff (adds contacts, payment details and products)
 *  - own:        the supplier and Topline admins (adds status and the rejection reason)
 */
class SupplierJson
{
    private static function categories(?string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $csv))));
    }

    public static function publicCard(Supplier $s, int $connected): array
    {
        return [
            'id' => $s->id,
            'slug' => $s->slug,
            'name' => $s->name,
            'logo' => $s->logo ?: null,
            'description' => $s->description,
            'categories' => self::categories($s->categories),
            'location' => $s->location,
            'connectedShops' => $connected,
        ];
    }

    public static function paymentOptions(?array $codes): array
    {
        return array_values(array_map(fn ($c) => ['id' => $c, 'label' => Suppliers::PAYMENT_OPTIONS[$c] ?? $c], $codes ?? []));
    }

    public static function forShop(Supplier $s, int $connected, ?iterable $products = null): array
    {
        $data = [
            ...self::publicCard($s, $connected),
            'phone' => Phone::display($s->phone),
            'email' => $s->email ?: null,
            'website' => $s->website ?: null,
            'minOrder' => $s->min_order,
            'deliveryInfo' => $s->delivery_info,
            'paymentOptions' => self::paymentOptions($s->payment_options),
            'paymentInstructions' => $s->payment_instructions ?? '',
        ];
        if ($products !== null) {
            $data['products'] = collect($products)->map(fn (SupplierProduct $p) => self::productForShop($p))->values()->all();
        }

        return $data;
    }

    public static function productForShop(SupplierProduct $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'unit' => $p->unit,
            'price' => $p->price,
            'minQty' => $p->min_qty,
            'inStock' => $p->in_stock,
            'image' => $p->image ?: null,
        ];
    }

    /** For the supplier themselves and for Topline admins. */
    public static function own(Supplier $s, int $connected = 0, array $extra = []): array
    {
        return [
            'id' => $s->id,
            'slug' => $s->slug,
            'status' => $s->status,
            'profile' => [
                'name' => $s->name,
                'logo' => $s->logo ?: null,
                'description' => $s->description,
                'categories' => self::categories($s->categories),
                'location' => $s->location,
                'phone' => Phone::display($s->phone),
                'email' => $s->email ?: null,
                'website' => $s->website ?: null,
                'minOrder' => $s->min_order,
                'deliveryInfo' => $s->delivery_info,
                'paymentOptions' => self::paymentOptions($s->payment_options),
                'paymentInstructions' => $s->payment_instructions ?? '',
            ],
            'rejectionReason' => $s->status === Supplier::REJECTED ? ($s->review_note ?: null) : null,
            'approvedAt' => Text::iso($s->approved_at),
            'createdAt' => Text::iso($s->created_at),
            'connectedShops' => $connected,
            ...$extra,
        ];
    }

    /** $audience: 'supplier' (sees the shop and its contact), 'shop' (sees the supplier), 'admin' (both). */
    public static function order(SupplierOrder $o, string $audience): array
    {
        $data = [
            'id' => $o->id,
            'reference' => $o->reference,
            'status' => $o->status,
            'items' => $o->items,
            'estimatedTotal' => $o->estimated_total,
            'deliveryLocation' => $o->delivery_location,
            'paymentOption' => ['id' => $o->payment_option, 'label' => Suppliers::PAYMENT_OPTIONS[$o->payment_option] ?? $o->payment_option],
            'notes' => $o->notes ?? '',
            'contactName' => $o->contact_name,
            'contactPhone' => Phone::display($o->contact_phone),
            'createdAt' => Text::iso($o->created_at),
            'updatedAt' => Text::iso($o->updated_at),
        ];
        if ($audience !== 'shop') {
            $data['store'] = $o->store ? ['id' => $o->store->id, 'name' => $o->store->name, 'town' => $o->store->town, 'county' => $o->store->county] : null;
        }
        if ($audience !== 'supplier') {
            $data['supplier'] = $o->supplier ? ['id' => $o->supplier->id, 'slug' => $o->supplier->slug, 'name' => $o->supplier->name,
                'phone' => Phone::display($o->supplier->phone)] : null;
        }

        return $data;
    }

    public static function creditRequest(CreditRequest $c, bool $forAdmin = false): array
    {
        $data = [
            'id' => $c->id,
            'reference' => $c->reference,
            'amount' => $c->amount,
            'purpose' => $c->purpose,
            'periodMonths' => $c->period_months,
            'monthlySales' => $c->monthly_sales,
            'contactName' => $c->contact_name,
            'contactPhone' => Phone::display($c->contact_phone),
            'notes' => $c->notes ?? '',
            'status' => $c->status,
            'createdAt' => Text::iso($c->created_at),
            'updatedAt' => Text::iso($c->updated_at),
        ];
        if ($forAdmin) {
            $data['adminNote'] = $c->admin_note ?? '';
            $data['store'] = $c->store ? ['id' => $c->store->id, 'name' => $c->store->name, 'town' => $c->store->town,
                'county' => $c->store->county, 'planId' => $c->store->plan_id, 'createdAt' => Text::iso($c->store->created_at)] : null;
        }

        return $data;
    }

    public static function expense(Expense $e): array
    {
        return [
            'id' => $e->id,
            'spentOn' => substr((string) $e->spent_on, 0, 10),
            'category' => $e->category,
            'amount' => $e->amount,
            'method' => $e->method,
            'description' => $e->description,
            'createdAt' => Text::iso($e->created_at),
        ];
    }
}
