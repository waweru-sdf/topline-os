<?php

namespace App\Services;

use App\Services\Sms\Sms;
use App\Support\Phone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telling Topline admins that something broke:
 *  - ALERT_WEBHOOK_URL: Slack / Discord / Microsoft Teams incoming webhook
 *  - ALERT_SMS_PHONES: SMS to these numbers
 *  - always: the log and the System health page.
 */
class Alerts
{
    /** Webhook messages waiting to go out once the response has been sent. */
    private static array $pending = [];

    private static ?int $hookedApp = null;

    public static function send(string $title, string $text): void
    {
        Log::warning("ALERT {$title} | ".str_replace("\n", ' | ', $text));
        $url = (string) config('topline.alert_webhook_url');
        if ($url !== '') {
            // After the response is sent, so a slow chat service never slows a customer's request.
            self::$pending[] = [$url, $title, $text];
            if (self::$hookedApp !== spl_object_id(app())) {
                self::$hookedApp = spl_object_id(app());
                app()->terminating(fn () => self::flush());
            }
        }
        foreach (config('topline.alert_sms_phones', []) as $phone) {
            try {
                if ($number = Phone::normalize($phone)) {
                    Sms::send($number, "Topline alert. {$title}: ".mb_substr($text, 0, 120));
                }
            } catch (Throwable $e) {
                Log::error('Alert SMS failed: '.$e->getMessage());
            }
        }
    }

    /** Send the queued webhook messages (each one once). */
    public static function flush(): void
    {
        [$queued, self::$pending] = [self::$pending, []];
        foreach ($queued as [$url, $title, $text]) {
            try {
                // "text" works for Slack and Teams; "content" for Discord.
                Http::timeout(5)->post($url, ['text' => "*{$title}*\n{$text}", 'content' => "**{$title}**\n{$text}"]);
            } catch (Throwable $e) {
                Log::error('Alert webhook failed: '.$e->getMessage());
            }
        }
    }
}
