<?php

namespace App\Http\Presenters;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Support\Phone;
use App\Support\Text;

/**
 * Model -> JSON, in exactly the camelCase shapes the website uses
 * (see docs/api-examples.json). Change a shape here and in the website together.
 */
class Json
{
    public static function plan(Plan $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'priceMonthly' => $p->price_monthly,
            'priceYearly' => $p->price_yearly,
            'blurb' => $p->blurb,
            'popular' => (bool) $p->popular,
            'limits' => (object) ($p->limits ?? []),
            'features' => $p->features ?? [],
        ];
    }

    public static function customer(Customer $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'creditLimit' => $c->credit_limit,
            'balance' => $c->balance,
            'dueAt' => Text::iso($c->due_at),
        ];
    }

    /** The session user. `storeId` and `storeRole` are set for shop staff. */
    public static function user(User $u, ?StoreMember $membership = null): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'phone' => $u->phone,
            'email' => $u->email,
            'role' => $u->role,
            'mustChangePassword' => (bool) $u->must_change_password,
            'storeId' => $membership?->store_id,
            'storeRole' => $membership?->role,
        ];
    }

    public static function quote(Quote $q): array
    {
        return [
            'id' => $q->id,
            'reference' => $q->reference,
            'name' => $q->name,
            'phone' => $q->phone,
            'items' => $q->items,
            'site' => $q->site,
            'status' => $q->status,
            'createdAt' => Text::iso($q->created_at),
        ];
    }

    /** $showCost adds the buying price: owners and managers only, never customers or cashiers. */
    public static function product(Product $p, bool $showCost = false): array
    {
        $data = [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->name,
            'categoryId' => $p->category_id,
            'unit' => $p->unit,
            'sellPrice' => $p->sell_price,
            'stock' => $p->stock,
            'reorderAt' => $p->reorder_at,
            'image' => $p->image,
            'gallery' => $p->gallery ?? [],
            'size' => $p->size ?? '',
            'specs' => $p->specs ?? [],
            'description' => $p->description ?? '',
            'altUnits' => $p->alt_units ?? [],
            'vatClass' => $p->vat_class,
            'published' => (bool) $p->published,
        ];
        if ($showCost) {
            $data['buyPrice'] = $p->buy_price;
        }

        return $data;
    }

    /** A counter sale or online order. `cost` per line only for people allowed to see margins. */
    public static function sale(Sale $s, bool $showCost = false): array
    {
        return [
            'id' => $s->code,
            'storeId' => $s->store_id,
            'channel' => $s->channel,
            'status' => $s->status,
            'method' => $s->method,
            'paymentMode' => $s->payment_mode,
            'subtotal' => $s->subtotal ?: $s->total,
            'deliveryFee' => $s->delivery_fee ?? 0,
            'total' => $s->total,
            'createdAt' => Text::iso($s->created_at),
            'paidAt' => Text::iso($s->paid_at),
            'staff' => $s->staff_name,
            'customerId' => $s->customer_id,
            'customerName' => $s->customer_name,
            'customerPhone' => $s->customer_phone,
            'delivery' => $s->delivery_type ? [
                'type' => $s->delivery_type, 'zone' => $s->delivery_zone,
                'address' => $s->delivery_address, 'notes' => $s->delivery_notes,
            ] : null,
            'mpesaRef' => $s->mpesa_ref,
            'items' => $s->items->map(function ($i) use ($showCost) {
                $line = ['productId' => $i->product_id, 'name' => $i->name, 'unit' => $i->unit, 'qty' => $i->qty, 'price' => $i->price];
                if ($showCost) {
                    $line['cost'] = $i->cost;
                }

                return $line;
            })->all(),
        ];
    }

    public static function payout(Payout $p): array
    {
        return [
            'id' => $p->id,
            'reference' => $p->reference,
            'orderId' => $p->sale_number ? 'S-'.$p->sale_number : null,
            'gross' => $p->gross,
            'commission' => $p->commission,
            'amount' => $p->amount,
            'method' => $p->method,
            'destination' => $p->destination,
            'status' => $p->status,
            'providerRef' => $p->provider_ref,
            'failureReason' => $p->failure_reason,
            'createdAt' => Text::iso($p->created_at),
            'paidAt' => Text::iso($p->paid_at),
        ];
    }

    /** What the M-PESA waiting screen needs: exact amount, who it's paid to, which phone. */
    public static function payment(Payment $p): array
    {
        return [
            'reference' => $p->reference,
            'status' => $p->status,
            'amount' => $p->amount,
            'payee' => $p->payee,
            'phone' => $p->phone,
            'mpesaRef' => $p->provider_ref,
            'failureReason' => $p->failure_reason,
            'simulated' => $p->provider === 'simulation',
        ];
    }

    /** How customers can pay this shop directly (shown at checkout). */
    public static function paymentsPublic(Store $store): array
    {
        $number = $store->payout_number;
        if (in_array($store->payout_method, [Store::POCHI, Store::SEND_MONEY], true) && $number) {
            $number = Phone::display($number);
        }

        return [
            'method' => $store->payout_method,
            'number' => $number,
            'account' => $store->payout_method === Store::PAYBILL ? $store->payout_account : '',
            'name' => $store->payout_name ?: $store->name,
            'acceptsDirect' => (bool) ($store->accepts_direct_payment && $store->canReceivePayouts()),
            'mpesaTill' => $store->payout_method === Store::TILL ? $store->payout_number : '',
        ];
    }

    /** What customers see on the storefront. */
    public static function storePublic(Store $store): array
    {
        return [
            'id' => $store->id,
            'slug' => $store->slug,
            'name' => $store->name,
            'tagline' => $store->tagline,
            'logo' => $store->logo,
            'county' => $store->county,
            'town' => $store->town,
            'address' => $store->address,
            'phone' => $store->phone,
            'whatsapp' => $store->whatsapp,
            'hours' => $store->hours,
            'accent' => $store->accent,
            'cover' => $store->cover,
            'delivery' => [
                'enabled' => (bool) $store->delivery_enabled,
                'mode' => $store->delivery_mode,
                'zones' => $store->delivery_zones ?? [],
                'freeOver' => $store->delivery_free_over,
                'note' => $store->delivery_note ?? '',
            ],
            'payments' => self::paymentsPublic($store),
            'status' => $store->status,
            'owner' => self::owner($store, ! $store->isOpen()), // closed stores show a contact number
        ];
    }

    /** What the store's own staff and Topline admins see. */
    public static function storePrivate(Store $store): array
    {
        return [
            ...self::storePublic($store),
            'payments' => [...self::paymentsPublic($store), 'number' => $store->payout_number, 'canReceive' => $store->canReceivePayouts()],
            'planId' => $store->plan_id,
            'billing' => ['period' => $store->billing_period],
            'customDomain' => $store->custom_domain,
            'createdAt' => Text::iso($store->created_at),
            'nextBillingAt' => Text::iso($store->next_billing_at),
            'owner' => self::owner($store, true),
        ];
    }

    public static function activity(ActivityLog $a, array $storeNames = []): array
    {
        return [
            'id' => $a->id,
            'kind' => $a->kind,
            'summary' => $a->summary,
            'actor' => $a->actor_name,
            'storeId' => $a->store_id,
            'storeName' => $storeNames[$a->store_id] ?? null,
            'data' => (object) ($a->data ?? []),
            'createdAt' => Text::iso($a->created_at),
        ];
    }

    private static function owner(Store $store, bool $withPhone): ?array
    {
        $owner = $store->owner();
        if (! $owner) {
            return null;
        }
        $data = ['name' => $owner->name];
        if ($withPhone) {
            $data['phone'] = Phone::display($owner->phone);
        }

        return $data;
    }
}
