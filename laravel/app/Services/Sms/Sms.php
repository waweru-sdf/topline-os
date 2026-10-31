<?php

namespace App\Services\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sending SMS (order alerts to shops and customers, problem alerts to Topline).
 *   SMS_MODE=simulation       messages go to the log (storage/logs/laravel.log)
 *   SMS_MODE=africastalking   real SMS: AT_USERNAME, AT_API_KEY and optional AT_SENDER_ID.
 *                             AT_USERNAME=sandbox uses their test environment.
 */
class Sms
{
    public const LIVE = 'https://api.africastalking.com/version1/messaging';
    public const SANDBOX = 'https://api.sandbox.africastalking.com/version1/messaging';

    /** Throws SmsException if the provider refuses the message. */
    public static function send(string $phone, string $message): void
    {
        if (config('topline.sms_mode') !== 'africastalking') {
            Log::info("[simulated SMS to {$phone}] {$message}");

            return;
        }
        $username = (string) config('topline.at_username');
        $key = (string) config('topline.at_api_key');
        if ($username === '' || $key === '') {
            throw new SmsException('SMS_MODE=africastalking needs AT_USERNAME and AT_API_KEY in .env.');
        }
        $form = ['username' => $username, 'to' => '+'.ltrim($phone, '+'), 'message' => $message];
        if ($sender = (string) config('topline.at_sender_id')) {
            $form['from'] = $sender;
        }
        try {
            $res = Http::asForm()->acceptJson()->withHeaders(['apiKey' => $key])->timeout(15)
                ->post($username === 'sandbox' ? self::SANDBOX : self::LIVE, $form);
        } catch (ConnectionException $e) {
            throw new SmsException("Couldn't reach Africa's Talking: ".$e->getMessage(), 0, $e);
        }
        if ($res->status() >= 400) {
            throw new SmsException("Africa's Talking said {$res->status()}: ".mb_substr($res->body(), 0, 200));
        }
        $recipients = $res->json('SMSMessageData.Recipients');
        if (! is_array($recipients)) {
            throw new SmsException("Unexpected reply from Africa's Talking: ".mb_substr($res->body(), 0, 200));
        }
        $failed = array_values(array_filter($recipients, fn ($r) => ($r['status'] ?? '') !== 'Success'));
        if (! $recipients || $failed) {
            throw new SmsException("SMS to {$phone} not sent: ".($failed[0]['status'] ?? 'no recipients'));
        }
        Log::info("SMS sent to {$phone} (".($recipients[0]['messageId'] ?? '').')');
    }
}
