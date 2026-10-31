<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Input;
use App\Support\Phone;
use App\Support\Text;
use Illuminate\Support\Facades\DB;

class Stores
{
    public static function checkSlug(mixed $raw): array
    {
        $slug = Text::slugify($raw);
        if (strlen($slug) < 3) {
            return ['slug' => $slug, 'available' => false, 'reason' => 'Use at least 3 letters or numbers.'];
        }
        if (in_array($slug, Text::RESERVED_SLUGS, true)) {
            return ['slug' => $slug, 'available' => false, 'reason' => 'That link is reserved. Try another.'];
        }
        $taken = Store::where('slug', $slug)->exists();

        return ['slug' => $slug, 'available' => ! $taken, 'reason' => $taken ? 'That link is already taken.' : null];
    }

    /**
     * Sign-up: owner + store, opened by the plan payment made just before.
     * Everything about the owner is checked BEFORE the payment is used, so a typo never wastes it.
     *
     * @return array{0: Store, 1: User, 2: StoreMember}
     */
    public static function create(array $data): array
    {
        $owner = is_array($data['owner'] ?? null) ? $data['owner'] : [];
        $business = is_array($data['business'] ?? null) ? $data['business'] : [];
        $billingInfo = is_array($data['billing'] ?? null) ? $data['billing'] : [];

        $ownerName = Input::text($owner, 'name', required: true, maxLength: 120, message: 'Enter your name.');
        $ownerPhone = Phone::normalize($owner['phone'] ?? null);
        if (! $ownerPhone) {
            throw ApiError::invalid('Enter a mobile number like 0712 345 678.', 'ownerPhone');
        }
        if (User::where('phone', $ownerPhone)->exists()) {
            throw ApiError::invalid('This number already has a Topline account. Log in instead.', 'ownerPhone');
        }
        try {
            $ownerEmail = Passwords::normalizeEmail($owner['email'] ?? null);
        } catch (ApiError $e) {
            throw ApiError::invalid($e->getMessage(), 'ownerEmail');
        }
        if ($ownerEmail && User::where('email', $ownerEmail)->exists()) {
            throw ApiError::invalid('This email already has a Topline account. Log in instead.', 'ownerEmail');
        }
        $ownerPassword = Passwords::checkNew($owner['password'] ?? null, 'ownerPassword');

        $name = Input::text($business, 'name', required: true, maxLength: 120, message: 'Enter your shop name.');
        $slugCheck = self::checkSlug(($data['slug'] ?? null) ?: $name);
        if (! $slugCheck['available']) {
            throw ApiError::invalid($slugCheck['reason'], 'slug');
        }
        $plan = Billing::plan($data['planId'] ?? null);

        return DB::transaction(function () use ($owner, $business, $billingInfo, $ownerName, $ownerPhone, $ownerEmail, $ownerPassword, $name, $slugCheck, $plan) {
            $payment = Billing::claimSignupPayment($billingInfo['paymentReference'] ?? null, $plan->id, $billingInfo['lastRef'] ?? null);
            $whatsapp = Phone::normalize($business['whatsapp'] ?? null) ?? $ownerPhone;
            $store = Store::create([
                'slug' => $slugCheck['slug'],
                'name' => $name,
                'tagline' => Input::text($business, 'tagline'),
                'county' => Input::text($business, 'county', maxLength: 40),
                'town' => Input::text($business, 'town', maxLength: 80),
                'address' => Input::text($business, 'address'),
                'phone' => Phone::display($ownerPhone),
                'whatsapp' => $whatsapp,
                'payout_method' => Store::TILL,
                'payout_number' => Input::text($business, 'till', maxLength: 20),
                'payout_name' => $name,
                'plan_id' => $plan->id,
                'status' => Store::DRAFT,
                'delivery_zones' => [],
                'delivery_note' => '',
            ]);
            $user = new User(['name' => $ownerName, 'phone' => $ownerPhone, 'email' => $ownerEmail, 'role' => User::VENDOR]);
            Passwords::set($user, $ownerPassword);
            $user->save();
            $membership = StoreMember::create(['store_id' => $store->id, 'user_id' => $user->id, 'role' => StoreMember::OWNER]);
            if (is_array($business['payments'] ?? null)) {
                self::updatePayments($store, $business['payments']);
                $store->save();
            }
            Billing::activate($store, $plan->id, $payment->period);
            Activity::record('store_created', "{$ownerName} opened {$name} on the {$plan->name} plan", $store->id, $user,
                ['plan' => $plan->id, 'period' => $payment->period, 'amount' => $payment->amount]);

            return [$store->refresh(), $user, $membership];
        });
    }

    /** Owner's Store settings. Plan, status and billing dates are not here: those change only through payments or admins. */
    public static function updateProfile(Store $store, array $data): Store
    {
        $textFields = ['name' => 120, 'tagline' => 200, 'county' => 40, 'town' => 80, 'address' => 200,
            'phone' => 20, 'hours' => 120, 'cover' => 300, 'logo' => 300];
        foreach ($textFields as $key => $max) {
            if (array_key_exists($key, $data)) {
                $value = Input::text($data, $key, maxLength: $max);
                if ($key === 'name' && $value === '') {
                    throw ApiError::invalid('Your shop needs a name.', 'name');
                }
                $store->{$key} = $value;
            }
        }
        if (array_key_exists('whatsapp', $data)) {
            $raw = Input::text($data, 'whatsapp', maxLength: 20);
            $number = $raw !== '' ? Phone::normalize($raw) : '';
            if ($raw !== '' && ! $number) {
                throw ApiError::invalid('Enter a WhatsApp number like 0712 345 678.', 'whatsapp');
            }
            $store->whatsapp = $number;
        }
        if (array_key_exists('accent', $data)) {
            if (! is_string($data['accent']) || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $data['accent'])) {
                throw ApiError::invalid('Pick a colour.', 'accent');
            }
            $store->accent = strtoupper($data['accent']);
        }
        if (is_array($data['delivery'] ?? null)) {
            self::updateDelivery($store, $data['delivery']);
        }
        if (is_array($data['payments'] ?? null)) {
            self::updatePayments($store, $data['payments']);
        }
        $changed = [];
        foreach (['payments' => 'how they get paid', 'delivery' => 'delivery', 'name' => 'shop details', 'logo' => 'pictures', 'cover' => 'pictures'] as $key => $label) {
            if (array_key_exists($key, $data) && ! in_array($label, $changed, true)) {
                $changed[] = $label;
            }
        }
        DB::transaction(function () use ($store, $changed) {
            $store->save();
            Activity::record('settings_updated', 'Updated '.($changed ? implode(', ', $changed) : 'shop settings'), $store->id);
        });

        return $store->refresh();
    }

    private static function updateDelivery(Store $store, array $d): void
    {
        if (array_key_exists('enabled', $d)) {
            $store->delivery_enabled = (bool) $d['enabled'];
        }
        if (array_key_exists('note', $d)) {
            $store->delivery_note = mb_substr((string) ($d['note'] ?? ''), 0, 1000);
        }
        if (array_key_exists('mode', $d)) {
            if (! in_array($d['mode'], Delivery::MODES, true)) {
                throw ApiError::invalid('Choose how delivery is charged.', 'deliveryMode');
            }
            $store->delivery_mode = $d['mode'];
        }
        if (array_key_exists('zones', $d)) {
            $store->delivery_zones = Delivery::cleanZones($d['zones']);
        }
        if (array_key_exists('freeOver', $d)) {
            $value = $d['freeOver'];
            $store->delivery_free_over = in_array($value, [null, '', 0, '0'], true) ? null : (int) $value;
        }
        if ($store->delivery_enabled && $store->delivery_mode === Store::DELIVERY_ZONES && ! $store->delivery_zones) {
            throw ApiError::invalid("Add at least one delivery area with its fee, or choose 'agree the cost with each customer'.", 'deliveryZones');
        }
    }

    /** How the shop receives money: till, paybill (+ account), Pochi la Biashara or Send Money. */
    private static function updatePayments(Store $store, array $p): void
    {
        $method = $p['method'] ?? $store->payout_method;
        if (! in_array($method, Store::PAYOUT_METHODS, true)) {
            throw ApiError::invalid('Choose how you receive money.', 'payoutMethod');
        }
        $number = trim((string) (array_key_exists('number', $p) ? $p['number'] : $store->payout_number));
        $account = mb_substr(trim((string) (array_key_exists('account', $p) ? $p['account'] : $store->payout_account)), 0, 40);
        if (in_array($method, [Store::TILL, Store::PAYBILL], true)) {
            $number = preg_replace('/\D/', '', $number);
            if ($number !== '' && (strlen($number) < 5 || strlen($number) > 8)) {
                throw ApiError::invalid('Till and paybill numbers have 5 to 8 digits.', 'payoutNumber');
            }
            if ($method === Store::PAYBILL && $number !== '' && $account === '') {
                throw ApiError::invalid('Enter the paybill account number.', 'payoutAccount');
            }
        } elseif ($number !== '') {
            $normalized = Phone::normalize($number);
            if (! $normalized) {
                throw ApiError::invalid('Enter the M-PESA phone number like 0712 345 678.', 'payoutNumber');
            }
            $number = $normalized;
        }
        $store->payout_method = $method;
        $store->payout_number = $number;
        $store->payout_account = $method === Store::PAYBILL ? $account : '';
        if (array_key_exists('name', $p)) {
            $store->payout_name = mb_substr((string) ($p['name'] ?? ''), 0, 120);
        }
        if (array_key_exists('acceptsDirect', $p)) {
            $store->accepts_direct_payment = (bool) $p['acceptsDirect'];
        }
    }
}
