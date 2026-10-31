<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Store;
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

    public static function newOrderForShop(Store $store, Sale $order): void
    {
        $lines = $order->items->take(3)->map(fn ($i) => "{$i->qty} {$i->name}")->implode(', ');
        $how = $order->status === Sale::AWAITING_CONFIRMATION ? 'pay on delivery check needed' : 'paid by M-PESA';
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
