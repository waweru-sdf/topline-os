<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Support\ApiError;
use Illuminate\Support\Facades\Cache;

/**
 * Editable content for the main Topline site, managed from Topline admin → Site content.
 * Image values are a picture key the website knows (e.g. "heroShelves") or an upload URL.
 * "…Points" values are one line per point; "faqs" is a JSON list of {q, a}.
 * Cached, since every visit to the main site reads it; saving clears the cache.
 */
class Site
{
    public const DEFAULTS = [
        'announcement' => '',

        'heroTitle' => 'Your hardware shop, online, with its own link.',
        'heroText' => 'Customers browse your cement, steel and mabati and pay by M-PESA. You run the counter, stock and credit from the same place.',
        'heroImage' => 'heroShelves',

        'featuresTitle' => 'One account runs your online store and your shop counter.',
        'feature1Title' => 'A store your customers can open on their phone',
        'feature1Text' => 'Your shop gets its own link to share on WhatsApp, Facebook and your signboard. Customers see what you stock, your prices per bag, length or metre, and pay by M-PESA.',
        'feature1Points' => "Your logo, location and opening hours\nPickup or delivery, your choice\nRequest a quote for big orders",
        'featureImageStore' => 'storefront',
        'feature2Title' => 'A till that sells the way a hardware counter does',
        'feature2Text' => 'Sell 20 bags of cement, 6 lengths of Y12 and 30 metres of cable in one sale. Take M-PESA, cash, bank or credit, and send the receipt on WhatsApp.',
        'feature2Points' => "Units you actually use: bags, lengths, kg, feet\nWorks on any phone, tablet or laptop\nEvery sale recorded with who made it",
        'featureImageTill' => 'toolWall',
        'feature3Title' => 'Know your stock, and who owes you',
        'feature3Text' => 'Every sale updates your stock. Topline tells you what is running low before a fundi asks for it, and keeps a clear record of every customer on credit.',
        'feature3Points' => "Low-stock alerts\nCredit limits, due dates and WhatsApp reminders\nProfit and loss, and expenses, in Accounting",
        'featureImageStock' => 'aisle',

        'suppliersKicker' => 'Connect to suppliers',
        'suppliersTitle' => 'Buy straight from manufacturers and distributors',
        'suppliersText' => 'Shops on Topline see each supplier’s products and prices and send orders from their shop admin. The supplier calls back to confirm delivery and payment.',
        'suppliersEmpty' => 'We’re signing up cement plants, steel mills and manufacturers abroad, including China. They’ll be listed here soon.',
        'suppliersJoinText' => 'Manufacturer or distributor? Sign up, and once Topline approves you, every hardware shop on Topline can order from you.',
        'creditTitle' => 'Stock credit',
        'creditText' => 'Stock up now and pay later, with credit from Topline’s lending partner based on your shop’s sales on Topline. Shop owners request it from their shop admin.',

        'stepsTitle' => 'Open your store today',
        'step1Title' => 'Tell us about your shop',
        'step1Text' => 'Your name, the shop name, where you are and your WhatsApp number. It takes about three minutes.',
        'step2Title' => 'Pick a plan and pay by M-PESA',
        'step2Text' => 'Approve the M-PESA prompt on your phone. Your store link goes live as soon as the payment comes through.',
        'step3Title' => 'Add products and share your link',
        'step3Text' => 'Add what you sell with prices per unit, then share your link with customers and fundis.',

        'pricingTitle' => 'Pricing',
        'pricingText' => 'Pay monthly or yearly by M-PESA or card. No setup fee, and you can change plan at any time.',
        'pricingNote' => 'Prices are placeholders until Topline confirms final pricing.',

        'faqTitle' => 'Questions shop owners ask',
        'faqs' => '[{"q":"Do I need a computer?","a":"No. Topline works on any smartphone browser. A tablet or laptop at the counter is handy for busy shops."},'
            .'{"q":"How do customers pay me?","a":"By M-PESA on your store page. The money goes to your account, not to Topline. You can also record cash, bank and credit sales at the counter."},'
            .'{"q":"What happens if I stop paying?","a":"You get reminders and a 7-day grace period. After that your store shows as closed to customers, but your records stay safe and come back the moment you pay."},'
            .'{"q":"Can I use my own website address?","a":"Every store gets a free link like yourshop.toplinehub.co.ke. Using your own domain is coming soon."},'
            .'{"q":"Does it do KRA eTIMS invoices?","a":"eTIMS support is on the way for VAT-registered shops. Tell us if you need it and we will put you on the early list."}]',

        'closingTitle' => 'Your customers are already on their phones.',
        'closingText' => 'Give them a link to your shop.',

        'reviewsTitle' => 'Tell us how a shop treated you',
        'reviewsText' => 'We value your feedback. Reviews go only to the Topline team, never to the shop or the public, and help us keep every hardware shop on Topline honest and helpful.',

        'signupImage' => 'ownerPhone',
        'footerText' => 'Shop software and an online store for hardware businesses in Kenya. Paid by M-PESA.',
        'supportPhone' => '0700 000 000',
        'supportWhatsapp' => '254700000000',
        'supportEmail' => 'hello@toplinehub.co.ke',
        'supportLocation' => 'Nairobi, Kenya',
    ];

    private const CACHE_KEY = 'site:content';

    private const FAQ_MAX = 15;

    public static function all(): array
    {
        // Only saved values are cached, so settings added in a later version still get their defaults.
        $saved = Cache::rememberForever(self::CACHE_KEY, fn () => SiteSetting::whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key')->all());

        return array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
    }

    public static function update(array $data): array
    {
        $unknown = array_diff(array_keys($data), array_keys(self::DEFAULTS));
        if ($unknown) {
            sort($unknown);
            throw ApiError::invalid('Unknown setting: '.implode(', ', $unknown).'.');
        }
        $clean = [];
        foreach ($data as $key => $value) {
            $value = $key === 'faqs' ? self::faqs($value) : ($value === null ? '' : trim(is_scalar($value) ? (string) $value : ''));
            $max = self::maxLength($key);
            if (mb_strlen($value) > $max) {
                throw ApiError::invalid("Keep this under {$max} characters.", $key);
            }
            $clean[$key] = $value;
        }
        foreach ($clean as $key => $value) { // all checked first, so a bad field saves nothing
            SiteSetting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_at' => now()]);
        }
        Cache::forget(self::CACHE_KEY);

        return self::all();
    }

    private static function maxLength(string $key): int
    {
        return match (true) {
            $key === 'faqs' => 20000,
            $key === 'announcement' => 200,
            str_ends_with($key, 'Title'), str_ends_with($key, 'Kicker') => 150,
            str_ends_with($key, 'Points') => 600,
            default => 600,
        };
    }

    /** A list of {q, a} (or its JSON) → canonical JSON. Empty questions are dropped. */
    private static function faqs(mixed $value): string
    {
        $list = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($list) || ! array_is_list($list)) {
            throw ApiError::invalid('The questions list is broken. Reload the page and try again.', 'faqs');
        }
        $out = [];
        foreach ($list as $item) {
            $q = trim((string) (is_array($item) ? ($item['q'] ?? '') : ''));
            $a = trim((string) (is_array($item) ? ($item['a'] ?? '') : ''));
            if ($q === '') {
                continue;
            }
            if (mb_strlen($q) > 200 || mb_strlen($a) > 1000) {
                throw ApiError::invalid('Keep each question under 200 characters and each answer under 1000.', 'faqs');
            }
            $out[] = ['q' => $q, 'a' => $a];
        }
        if (count($out) > self::FAQ_MAX) {
            throw ApiError::invalid('Up to '.self::FAQ_MAX.' questions.', 'faqs');
        }

        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
