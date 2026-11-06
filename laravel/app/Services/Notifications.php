<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Services\Sms\Sms;
use App\Support\Phone;
use App\Support\Text;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Messages to shop owners and customers by SMS (logged while SMS_MODE=simulation). */
class Notifications
{
    private static function send(?string $phone, string $text): void
    {
        $number = Phone::normalize($phone);
        if (! $number) {
            return;
        }
        try {
            Sms::send($number, $text);
        } catch (Throwable $e) {
            Log::error("SMS to {$number} failed: ".$e->getMessage()); // a failed SMS must never fail an order
        }
    }

    public static function newSupplierOrder(Supplier $supplier, Store $store, SupplierOrder $order): void
    {
        $lines = collect($order->items)->take(3)->map(fn ($i) => "{$i['qty']} {$i['name']}")->implode(', ');
        self::send($supplier->phone ?: $supplier->user?->phone,
            "New Topline order {$order->reference} from {$store->name} ({$store->town}): {$lines}. Call ".Phone::display($order->contact_phone).'. Details in your Topline supplier account.');
    }

    public static function tillCodeReviewed(Payment $payment): void
    {
        $text = $payment->status === Payment::PAID
            ? "Topline: payment {$payment->provider_ref} confirmed. Your plan is active. Thank you!"
            : "Topline: we couldn't confirm M-PESA code {$payment->provider_ref}: {$payment->failure_reason} Pay again from Plan & billing, or WhatsApp us.";
        self::send($payment->phone, $text);
    }

    public static function supplierReviewed(Supplier $supplier): void
    {
        $text = $supplier->status === Supplier::APPROVED
            ? "Topline: {$supplier->name} is approved. Log in at ".config('app.url').'/login with your phone number and password, then add your logo and products.'
            : "Topline: your supplier sign-up for {$supplier->name} wasn't approved".($supplier->review_note ? ": {$supplier->review_note}" : '.');
        self::send($supplier->user?->phone ?? $supplier->phone, $text);
    }

    public static function newOrderForShop(Store $store, Sale $order): void
    {
        $lines = $order->items->take(3)->map(fn ($i) => "{$i->qty} {$i->name}")->implode(', ');
        $how = match ($order->payment_mode) {
            Sale::PAY_DIRECT => "customer paid your number, code {$order->mpesa_ref}: check it",
            Sale::PAY_LATER => 'customer pays at pickup or on delivery',
            default => 'paid by M-PESA',
        };
        self::send($store->whatsapp ?: $store->owner()?->phone,
            "New order {$order->code} on Topline: {$lines}. ".Text::kes($order->total)." ({$how}). Open your admin to confirm.");
    }

    public static function orderUpdateForCustomer(Store $store, Sale $order): void
    {
        $total = Text::kes($order->total);
        $messages = [
            'new' => "{$store->name}: we've received order {$order->code} ({$total}). We'll call you to confirm.",
            'confirmed' => "{$store->name}: order {$order->code} is confirmed and being prepared.",
            'dispatched' => "{$store->name}: order {$order->code} is on the way.",
            'completed' => "{$store->name}: order {$order->code} is complete. Asante!",
            'cancelled' => "{$store->name}: order {$order->code} was cancelled. Call {$store->phone} for help.",
        ];
        if (isset($messages[$order->status]) && $order->customer_phone) {
            self::send($order->customer_phone, $messages[$order->status]);
        }
    }
}
