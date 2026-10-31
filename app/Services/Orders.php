<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Store;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Phone;
use App\Support\Text;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Online orders from a storefront.
 * 1. place(): checks and HOLDS stock (atomically, see Stock::reserve), adds the delivery fee, then either
 *    - "prompt": one M-PESA prompt for the exact total (goods + delivery), or
 *    - "direct": the customer paid the shop's own till/paybill/Pochi/number and gives the M-PESA code;
 *      the shop confirms it received the money.
 * 2. Paid (prompt) or confirmed (direct) -> "new" in the shop's admin, shop notified, money sent to the shop.
 * 3. Declined / unanswered / cancelled -> the held stock goes back on the shelf.
 */
class Orders
{
    public const MAX_LINES = 50;
    public const MPESA_CODE = '/^[A-Z0-9]{8,12}$/';

    /** @return array{0: Sale, 1: ?Payment} */
    public static function place(Store $store, array $customer, mixed $items, ?array $delivery, string $paymentMode = Sale::PAY_PROMPT, mixed $mpesaCode = null): array
    {
        if (! $store->isOpen()) {
            throw new ApiError("{$store->name} isn't taking online orders right now.", 409);
        }
        $name = trim((string) ($customer['name'] ?? ''));
        $phone = Phone::normalize($customer['phone'] ?? null);
        if ($name === '') {
            throw ApiError::invalid('Enter your name.', 'name');
        }
        if (! $phone) {
            throw ApiError::invalid('Enter an M-PESA number like 0712 345 678.', 'phone');
        }
        if (! in_array($paymentMode, [Sale::PAY_PROMPT, Sale::PAY_DIRECT], true)) {
            throw ApiError::invalid('Choose how to pay.', 'paymentMode');
        }
        $code = null;
        if ($paymentMode === Sale::PAY_DIRECT) {
            if (! ($store->accepts_direct_payment && $store->canReceivePayouts())) {
                throw ApiError::invalid("{$store->name} only takes payment by M-PESA prompt.", 'paymentMode');
            }
            $code = strtoupper(trim((string) ($mpesaCode ?? '')));
            if (! preg_match(self::MPESA_CODE, $code)) {
                throw ApiError::invalid('Enter the M-PESA code from your confirmation SMS, e.g. TJK4H2PQ9X.', 'mpesaCode');
            }
        }

        [$lines, $products] = self::buildLines($store, $items);
        $subtotal = array_sum(array_map(fn ($l) => $l['qty'] * $l['price'], $lines));
        [$deliveryType, $zone, $fee] = Delivery::quote($store, $delivery, $subtotal);
        $delivery ??= [];

        // Create the order and hold its stock in one transaction: all items or none.
        $order = DB::transaction(function () use ($store, $paymentMode, $name, $phone, $deliveryType, $zone, $delivery, $lines, $products, $subtotal, $fee, $code) {
            $order = Sale::create([
                'store_id' => $store->id,
                'channel' => Sale::ONLINE,
                'status' => $paymentMode === Sale::PAY_PROMPT ? Sale::AWAITING_PAYMENT : Sale::AWAITING_CONFIRMATION,
                'method' => 'mpesa',
                'payment_mode' => $paymentMode,
                'customer_name' => mb_substr($name, 0, 120),
                'customer_phone' => $phone,
                'delivery_type' => $deliveryType,
                'delivery_zone' => $zone,
                'delivery_address' => mb_substr(trim((string) ($delivery['address'] ?? '')), 0, 200) ?: null,
                'delivery_notes' => mb_substr(trim((string) ($delivery['notes'] ?? '')), 0, 1000) ?: null,
                'subtotal' => $subtotal,
                'delivery_fee' => $fee,
                'total' => $subtotal + $fee,
                'mpesa_ref' => $code,
            ]);
            foreach ($lines as $line) {
                $order->items()->create($line);
            }
            foreach ($products as $i => $product) {
                Stock::reserve($product, $lines[$i]['qty'], $order->code);
            }
            $how = $paymentMode === Sale::PAY_DIRECT ? 'paid to shop directly, waiting for check' : 'waiting for M-PESA';
            Activity::record('order_placed', "{$name} ordered ".self::itemsText($lines).' — '.Text::kes($order->total)." ({$how})",
                $store->id, "Customer {$name}", ['order' => $order->code, 'total' => $order->total]);

            return $order;
        });
        $order->load('items');

        if ($paymentMode === Sale::PAY_DIRECT) {
            Notifications::newOrderForShop($store, $order);

            return [$order, null];
        }
        try {
            $payment = PaymentRecords::start('order', $order->total, $phone, 'ORD', mb_substr(strtoupper($store->name), 0, 40),
                ['store_id' => $store->id, 'sale_number' => $order->number]);
        } catch (Throwable $e) {
            // The prompt couldn't be sent: cancel the order and put its stock back.
            DB::transaction(function () use ($order) {
                $order->update(['status' => Sale::CANCELLED]);
                self::releaseStock($order, 'payment_failed');
            });
            throw $e;
        }
        $order->update(['payment_reference' => $payment->reference]);

        return [$order->refresh()->load('items'), $payment];
    }

    public static function find(Store $store, mixed $code): Sale
    {
        $number = Sale::numberFromCode($code);
        $order = $number ? Sale::with('items')->find($number) : null;
        if (! $order || $order->store_id !== $store->id) {
            throw ApiError::notFound('Order not found.');
        }

        return $order;
    }

    /** Polled by the checkout page until M-PESA confirms. @return array{0: Sale, 1: ?Payment} */
    public static function paymentStatus(Store $store, mixed $code): array
    {
        $order = self::find($store, $code);
        $payment = $order->payment_reference ? Payment::find($order->payment_reference) : null;
        if ($payment) {
            $payment = PaymentRecords::refresh($payment);
            $order->refresh()->load('items');
        }

        return [$order, $payment];
    }

    /** The order's M-PESA payment is confirmed: it reaches the shop and the shop gets its money. */
    public static function markPaid(Payment $payment): void
    {
        $order = Sale::with('items')->find($payment->sale_number);
        if (! $order) {
            return;
        }
        if ($order->status !== Sale::AWAITING_PAYMENT) {
            if ($order->status === Sale::CANCELLED) {
                Monitoring::recordIncident('payment', "Payment arrived after {$order->code} was cancelled",
                    "M-PESA ref {$payment->provider_ref}, ".Text::kes($payment->amount).'. Refund the customer or re-open the order.');
            }

            return;
        }
        $store = Store::find($order->store_id);
        $order->update(['status' => Sale::NEW, 'paid_at' => now(), 'mpesa_ref' => $payment->provider_ref]);
        Activity::record('order_paid', "{$order->code} paid by M-PESA (".Text::kes($order->total).", ref {$payment->provider_ref})",
            $store->id, "Customer {$order->customer_name}", ['order' => $order->code, 'total' => $order->total]);
        Payouts::payShopForOrder($store, $order);
        Notifications::newOrderForShop($store, $order);
        Notifications::orderUpdateForCustomer($store, $order);
    }

    /** Prompt declined or unanswered: cancel the order and put its stock back. */
    public static function releaseUnpaid(Payment $payment): void
    {
        $order = Sale::with('items')->find($payment->sale_number);
        if (! $order || $order->status !== Sale::AWAITING_PAYMENT) {
            return;
        }
        $order->update(['status' => Sale::CANCELLED]);
        self::releaseStock($order, 'payment_failed');
        Activity::record('order_status', "{$order->code} cancelled: M-PESA payment not completed, stock put back",
            $order->store_id, 'System', ['order' => $order->code]);
    }

    public static function updateStatus(Store $store, mixed $code, mixed $newStatus, ?User $user = null): Sale
    {
        $order = self::find($store, $code);
        $allowed = Sale::ORDER_TRANSITIONS[$order->status] ?? [];
        if (! in_array($newStatus, $allowed, true)) {
            throw ApiError::invalid("An order that is '{$order->status}' can't be moved to '".(is_scalar($newStatus) ? $newStatus : '')."'.", 'status');
        }
        DB::transaction(function () use ($order, $newStatus, $store, $user) {
            if ($newStatus === Sale::NEW && $order->status === Sale::AWAITING_CONFIRMATION) {
                $order->paid_at = now(); // the shop confirmed the direct M-PESA payment arrived
            }
            if ($newStatus === Sale::CANCELLED) {
                self::releaseStock($order, 'cancelled');
            }
            Activity::record('order_status', "{$order->code}: ".str_replace('_', ' ', $order->status).' → '.str_replace('_', ' ', $newStatus),
                $store->id, $user, ['order' => $order->code, 'before' => $order->status, 'after' => $newStatus]);
            $order->status = $newStatus;
            $order->save();
        });
        Notifications::orderUpdateForCustomer($store, $order);

        return $order;
    }

    private static function releaseStock(Sale $order, string $reason): void
    {
        foreach ($order->items as $line) {
            if ($line->product_id && ($product = Product::find($line->product_id))) {
                Stock::release($product, $line->qty, $order->code, $reason);
            }
        }
    }

    /** @return array{0: array, 1: Product[]} */
    private static function buildLines(Store $store, mixed $items): array
    {
        if (! is_array($items) || ! $items || ! array_is_list($items)) {
            throw ApiError::invalid('Your cart is empty.');
        }
        if (count($items) > self::MAX_LINES) {
            throw ApiError::invalid('Orders can have up to '.self::MAX_LINES.' different items.');
        }
        $lines = [];
        $products = [];
        foreach ($items as $item) {
            $product = Product::find((string) ($item['productId'] ?? ''));
            if (! $product || $product->store_id !== $store->id || ! $product->published) {
                throw new ApiError('An item in your cart is no longer available. Remove it and try again.', 409);
            }
            $qty = $item['qty'] ?? null;
            $qty = is_numeric($qty) ? (int) $qty : 0;
            if ($qty < 1 || $qty > 100000) {
                throw ApiError::invalid('Quantities must be between 1 and 100,000.', 'qty');
            }
            $lines[] = ['product_id' => $product->id, 'name' => $product->name, 'unit' => $product->unit, 'qty' => $qty,
                'price' => $product->sell_price, 'cost' => $product->buy_price];
            $products[] = $product;
        }

        return [$lines, $products];
    }

    private static function itemsText(array $lines): string
    {
        $first = "{$lines[0]['qty']} × {$lines[0]['name']}";

        return count($lines) === 1 ? $first : $first.' and '.(count($lines) - 1).' more';
    }
}
