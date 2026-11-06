<?php

/*
| Topline settings, read from .env. Every value has a safe development default.
*/

return [
    // Shop links: https://ROOT_DOMAIN/s/<shop>
    'root_domain' => env('ROOT_DOMAIN', 'toplinehub.co.ke'),
    // Shop days (dashboard grouping, daily billing check) follow Kenyan time.
    'timezone' => env('TOPLINE_TIMEZONE', 'Africa/Nairobi'),

    // Payments: "mpesa" (plans paid to Topline's own Till), "simulation" (no money moves; tests and demos)
    // or "paystack" (kept for later: Topline collects online orders and pays shops out).
    'payments_mode' => env('PAYMENTS_MODE', 'simulation'),
    // Topline's M-PESA Till: plan payments go here. With the Daraja keys below, customers get an M-PESA
    // prompt (STK push); without them, they pay from their M-PESA menu and enter the code for an admin to check.
    'mpesa' => [
        'till' => env('MPESA_TILL', '987715'),
        'till_name' => env('MPESA_TILL_NAME', 'Topline'),
        // Safaricom Daraja (M-PESA Express) for this till, from developer.safaricom.co.ke after "Go Live":
        'env' => env('MPESA_ENV', 'production'), // production | sandbox
        'consumer_key' => env('MPESA_CONSUMER_KEY', ''),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET', ''),
        'shortcode' => env('MPESA_SHORTCODE', ''), // the till's store (head office) number; often not the till number
        'passkey' => env('MPESA_PASSKEY', ''),
    ],
    'paystack_secret_key' => env('PAYSTACK_SECRET_KEY', ''),
    'paystack_public_key' => env('PAYSTACK_PUBLIC_KEY', ''),
    // Simulation: "prompt" waits for the on-screen test phone; "auto" approves after the delay.
    'simulation_approval' => env('SIMULATION_APPROVAL', 'prompt'),
    'simulated_payment_delay_seconds' => (float) env('SIMULATED_PAYMENT_DELAY_SECONDS', 2),
    // An unanswered M-PESA prompt is treated as failed after this long (its stock is released).
    'payment_timeout_minutes' => (int) env('PAYMENT_TIMEOUT_MINUTES', 10),
    // Topline's cut of online orders before paying the shop (0 = none).
    'platform_commission_percent' => (float) env('PLATFORM_COMMISSION_PERCENT', 0),

    // SMS (order alerts, problem alerts): "simulation" writes them to the log; "africastalking" sends them.
    'sms_mode' => env('SMS_MODE', 'simulation'),
    'at_username' => env('AT_USERNAME', ''),
    'at_api_key' => env('AT_API_KEY', ''),
    'at_sender_id' => env('AT_SENDER_ID', ''),

    // Plans
    'grace_days' => (int) env('GRACE_DAYS', 7),

    // Photos uploaded by shops and admins
    'max_upload_mb' => (int) env('MAX_UPLOAD_MB', 5),
    'upload_storage' => env('UPLOAD_STORAGE', 'disk'), // disk (storage/app/uploads) or database
    'upload_folder' => null, // tests point this at a temporary folder

    // Alerts when something breaks
    'alert_webhook_url' => env('ALERT_WEBHOOK_URL', ''),
    'alert_sms_phones' => array_values(array_filter(array_map('trim', explode(',', (string) env('ALERT_SMS_PHONES', ''))))),

    // Background jobs over HTTP (only if `php artisan schedule:run` can't be used): /api/cron/run?key=...
    // First Topline admin, created by the cron (topline:deploy) when the site has none.
    // Remove FIRST_ADMIN_PASSWORD from .env once you've logged in.
    'first_admin' => [
        'name' => env('FIRST_ADMIN_NAME', ''),
        'phone' => env('FIRST_ADMIN_PHONE', ''),
        'email' => env('FIRST_ADMIN_EMAIL', ''),
        'password' => env('FIRST_ADMIN_PASSWORD', ''),
    ],

    'cron_secret' => env('CRON_SECRET', ''),
];
