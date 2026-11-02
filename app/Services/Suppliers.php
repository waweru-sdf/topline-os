<?php

namespace App\Services;

use App\Http\Presenters\SupplierJson;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Input;
use App\Support\Phone;
use App\Support\Text;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Connect to suppliers: sign-up, profiles, products and orders.
 *
 * A supplier signs up themselves; a Topline admin approves or rejects the sign-up. Only then
 * can they log in. After approval, the supplier's profile and product edits go live at once
 * (a Topline admin can hide a supplier at any time).
 */
class Suppliers
{
    /** How a hardware shop can pay a supplier. Money moves between them, outside Topline. */
    public const PAYMENT_OPTIONS = [
        'mpesa' => 'M-PESA (Paybill or Till)',
        'bank' => 'Bank transfer (PesaLink, RTGS or EFT)',
        'on_delivery' => 'Pay on delivery',
        'deposit' => 'Deposit now, balance on delivery',
        'supplier_credit' => 'Supplier credit / LPO (pay in 30–90 days)',
        'topline_credit' => 'Topline stock credit',
    ];

    public const MAX_PRODUCTS = 300;
    public const MAX_ORDERS_PER_DAY = 20;
    /** The home page's supplier list is cached, so landing visits don't query the database. */
    private const PUBLIC_CACHE_KEY = 'suppliers:public';
    private const PUBLIC_CACHE_SECONDS = 600;

    // ---------------------------------------------------------------- sign-up and approval

    /** A supplier signs up: login + starting profile, waiting for a Topline admin. */
    public static function apply(array $data): Supplier
    {
        $profile = self::validateProfile($data);
        $contact = Input::text($data, 'contactName', true, 120, 'Enter your name.');
        $email = $profile['email'] ?: null;
        if (User::where('phone', $profile['phone'])->exists()) {
            throw ApiError::invalid('That number already has a Topline login. Log in, or use a different number.', 'phone');
        }
        if ($email && User::where('email', $email)->exists()) {
            throw ApiError::invalid('That email already has a Topline login.', 'email');
        }
        $password = Passwords::checkNew($data['password'] ?? null);

        $supplier = DB::transaction(function () use ($profile, $contact, $email, $password) {
            $user = new User(['name' => $contact, 'phone' => $profile['phone'], 'email' => $email, 'role' => User::SUPPLIER]);
            Passwords::set($user, $password);
            $user->save();

            return Supplier::create([...$profile, 'user_id' => $user->id, 'slug' => self::uniqueSlug($profile['name']), 'status' => Supplier::PENDING]);
        });
        Activity::record('supplier_review', "{$supplier->name} signed up as a supplier and is waiting for approval", null, $supplier->user);

        return $supplier;
    }

    private static function uniqueSlug(string $name): string
    {
        $base = Text::slugify($name, 50) ?: 'supplier';
        $slug = $base;
        for ($i = 2; Supplier::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /** Called at login: suppliers get in only once approved. */
    public static function checkCanLogIn(User $user): void
    {
        $supplier = Supplier::where('user_id', $user->id)->first();
        if ($supplier?->status === Supplier::PENDING) {
            throw ApiError::forbidden('Your supplier sign-up is waiting for Topline’s approval. We’ll send you an SMS once it’s approved.');
        }
        if (! $supplier?->approved_at) {
            $why = $supplier?->review_note ? ": {$supplier->review_note}" : '.';
            throw ApiError::forbidden("Your supplier sign-up wasn’t approved{$why} Contact Topline for help.");
        }
    }

    /** A Topline admin approves or rejects a sign-up. A rejected one can still be approved later. */
    public static function review(Supplier $supplier, string $action, string $note, User $admin): Supplier
    {
        $open = $action === 'reject' ? [Supplier::PENDING] : [Supplier::PENDING, Supplier::REJECTED];
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw ApiError::invalid('Choose approve or reject.', 'action');
        }
        if (! in_array($supplier->status, $open, true)) {
            throw ApiError::invalid('This supplier has already been approved.', 'action');
        }
        if ($action === 'approve') {
            $supplier->status = Supplier::APPROVED;
            $supplier->approved_at = now();
            $supplier->review_note = '';
        } else {
            $supplier->status = Supplier::REJECTED;
            $supplier->review_note = mb_substr(trim($note), 0, 300);
        }
        $supplier->save();
        self::forgetPublicList();
        Activity::record('supplier_review', "{$admin->name} ".($action === 'approve' ? 'approved' : 'rejected')." supplier {$supplier->name}", null, $admin);
        Notifications::supplierReviewed($supplier);

        return $supplier;
    }

    public static function pendingSignUps(): int
    {
        return Supplier::where('status', Supplier::PENDING)->count();
    }

    public static function setListed(Supplier $supplier, bool $listed, User $admin): Supplier
    {
        if (! $supplier->approved_at) {
            throw ApiError::invalid('Approve the supplier first.', 'status');
        }
        $supplier->status = $listed ? Supplier::APPROVED : Supplier::HIDDEN;
        $supplier->save();
        self::forgetPublicList();
        Activity::record('supplier_review', "{$admin->name} ".($listed ? 'listed' : 'hid')." supplier {$supplier->name}", null, $admin);

        return $supplier;
    }

    // ---------------------------------------------------------------- profile and products (live at once)

    public static function validateProfile(array $data): array
    {
        $website = Input::text($data, 'website', false, 200);
        if ($website !== '' && ! preg_match('#^https?://[^\s<>"]+$#i', $website)) {
            throw ApiError::invalid('Enter the full web address, starting with https://', 'website');
        }
        $logo = Input::text($data, 'logo', false, 300);
        if ($logo !== '' && ! preg_match('#^https?://[^\s<>"]+$#i', $logo)) {
            throw ApiError::invalid('Upload the logo again.', 'logo');
        }
        $email = Passwords::normalizeEmail($data['email'] ?? null);
        $phone = Phone::normalize($data['phone'] ?? null) ?? throw ApiError::invalid('Enter a mobile number like 0712 345 678.', 'phone');
        $options = $data['paymentOptions'] ?? [];
        if (! is_array($options) || array_diff($options, array_keys(self::PAYMENT_OPTIONS))) {
            throw ApiError::invalid('Choose from the listed payment options.', 'paymentOptions');
        }
        $options = array_values(array_intersect(array_keys(self::PAYMENT_OPTIONS), $options)); // known order, no duplicates
        if (! $options) {
            throw ApiError::invalid('Tick at least one way shops can pay you.', 'paymentOptions');
        }
        $categories = collect(explode(',', Input::text($data, 'categories', false, 300)))
            ->map(fn ($c) => trim($c))->filter()->unique()->take(10)->implode(', ');

        return [
            'name' => Input::text($data, 'name', true, 120, 'Enter your company name.'),
            'logo' => $logo,
            'description' => Input::text($data, 'description', true, 1000, 'Describe what you supply.'),
            'categories' => $categories,
            'location' => Input::text($data, 'location', false, 120),
            'phone' => $phone,
            'email' => $email ?? '',
            'website' => $website,
            'min_order' => Input::text($data, 'minOrder', false, 120),
            'delivery_info' => Input::text($data, 'deliveryInfo', false, 300),
            'payment_options' => $options,
            'payment_instructions' => Input::text($data, 'paymentInstructions', false, 1000),
        ];
    }

    public static function updateProfile(Supplier $supplier, array $data): Supplier
    {
        $supplier->fill(self::validateProfile($data));
        $supplier->save();
        self::forgetPublicList();

        return $supplier;
    }

    public static function validateProduct(array $data): array
    {
        $price = $data['price'] ?? null;
        if ($price !== null && $price !== '') {
            $price = Input::integer($data, 'price', false, 1, 'Enter a price of at least KSh 1, or leave it empty for "price on request".');
        } else {
            $price = null;
        }
        $image = Input::text($data, 'image', false, 300);
        if ($image !== '' && ! preg_match('#^https?://[^\s<>"]+$#i', $image)) {
            throw ApiError::invalid('Upload the photo again.', 'image');
        }

        return [
            'name' => Input::text($data, 'name', true, 160, 'Enter the product name.'),
            'description' => Input::text($data, 'description', false, 500),
            'unit' => Input::text($data, 'unit', false, 40, null, 'piece'),
            'price' => $price,
            'min_qty' => max(1, Input::integer($data, 'minQty', false, 1, null, 1)),
            'in_stock' => filter_var($data['inStock'] ?? true, FILTER_VALIDATE_BOOL),
            'image' => $image,
        ];
    }

    public static function saveProduct(Supplier $supplier, ?SupplierProduct $product, array $data): SupplierProduct
    {
        $fields = self::validateProduct($data);
        if (! $product && $supplier->products()->count() >= self::MAX_PRODUCTS) {
            throw ApiError::invalid('You’ve reached '.self::MAX_PRODUCTS.' products. Remove some first.', 'name');
        }
        $product ??= new SupplierProduct(['supplier_id' => $supplier->id]);
        $product->fill($fields)->save();

        return $product;
    }

    // ---------------------------------------------------------------- what shops and the home page see

    /** @return array<int, array> the home page's supplier cards (no contacts or prices) */
    public static function publicList(): array
    {
        return Cache::remember(self::PUBLIC_CACHE_KEY, self::PUBLIC_CACHE_SECONDS, function () {
            $counts = self::connectedCounts();

            return self::listed()->map(fn ($s) => SupplierJson::publicCard($s, $counts[$s->id] ?? 0))->values()->all();
        });
    }

    public static function forgetPublicList(): void
    {
        Cache::forget(self::PUBLIC_CACHE_KEY);
    }

    // ---------------------------------------------------------------- orders

    public static function listed(): Collection
    {
        return Supplier::where('status', Supplier::APPROVED)->orderBy('name')->get();
    }

    public static function findListed(string $slugOrId): Supplier
    {
        return Supplier::where('status', Supplier::APPROVED)
            ->where(fn ($q) => $q->where('slug', $slugOrId)->orWhere('id', $slugOrId))->first()
            ?? throw ApiError::notFound('That supplier isn’t listed.');
    }

    /** How many different hardware shops have ordered from each supplier. */
    public static function connectedCounts(): array
    {
        return SupplierOrder::where('status', '!=', 'cancelled')
            ->select('supplier_id', DB::raw('COUNT(DISTINCT store_id) as shops'))->groupBy('supplier_id')
            ->pluck('shops', 'supplier_id')->map(fn ($n) => (int) $n)->all();
    }

    public static function placeOrder(Store $store, Supplier $supplier, array $data, User $by): SupplierOrder
    {
        if (! $supplier->isListed()) {
            throw ApiError::notFound('That supplier isn’t listed.');
        }
        $today = SupplierOrder::where('store_id', $store->id)->where('created_at', '>=', now()->subDay())->count();
        if ($today >= self::MAX_ORDERS_PER_DAY) {
            throw new ApiError('You’ve sent a lot of supplier orders today. Try again tomorrow.', 429);
        }
        $lines = $data['items'] ?? null;
        if (! is_array($lines) || ! $lines || ! array_is_list($lines) || count($lines) > 50) {
            throw ApiError::invalid('Add at least one item (up to 50).', 'items');
        }
        $products = SupplierProduct::where('supplier_id', $supplier->id)->get()->keyBy('id');
        $items = [];
        $total = 0;
        $priced = false;
        foreach ($lines as $line) {
            if (! is_array($line)) {
                throw ApiError::invalid('Check the items.', 'items');
            }
            $qty = Input::integer($line, 'qty', true, 1, 'Enter a quantity of at least 1.');
            if ($qty > 1000000) {
                throw ApiError::invalid('That quantity is too large.', 'items');
            }
            if (! empty($line['productId'])) {
                $p = $products->get((string) $line['productId']) ?? throw ApiError::invalid('One of the products is no longer available.', 'items');
                if ($qty < $p->min_qty) {
                    throw ApiError::invalid("{$p->name}: the minimum order is {$p->min_qty} {$p->unit}.", 'items');
                }
                $items[] = ['productId' => $p->id, 'name' => $p->name, 'unit' => $p->unit, 'qty' => $qty, 'price' => $p->price];
                if ($p->price !== null) {
                    $total += $p->price * $qty;
                    $priced = true;
                }
            } else {
                $name = Input::text($line, 'name', true, 160, 'Describe each item you need.');
                $items[] = ['productId' => null, 'name' => $name, 'unit' => Input::text($line, 'unit', false, 40), 'qty' => $qty, 'price' => null];
            }
        }
        $payment = (string) ($data['paymentOption'] ?? '');
        if (! in_array($payment, $supplier->payment_options ?? [], true)) {
            throw ApiError::invalid('Choose one of the ways this supplier accepts payment.', 'paymentOption');
        }
        $phone = Phone::normalize($data['contactPhone'] ?? null) ?? throw ApiError::invalid('Enter a mobile number like 0712 345 678.', 'contactPhone');

        $order = SupplierOrder::create([
            'reference' => 'PO-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)),
            'supplier_id' => $supplier->id,
            'store_id' => $store->id,
            'placed_by' => $by->id,
            'contact_name' => Input::text($data, 'contactName', true, 120, 'Enter who the supplier should talk to.'),
            'contact_phone' => $phone,
            'items' => $items,
            'estimated_total' => $priced ? $total : null,
            'delivery_location' => Input::text($data, 'deliveryLocation', true, 200, 'Enter where the goods should go.'),
            'payment_option' => $payment,
            'notes' => Input::text($data, 'notes', false, 1000),
            'status' => 'new',
            'updated_at' => now(),
        ]);
        Activity::record('supplier_order', "{$store->name} sent order {$order->reference} to {$supplier->name}", $store->id, $by);
        self::forgetPublicList(); // "works with N shops" may have changed
        Notifications::newSupplierOrder($supplier, $store, $order);

        return $order;
    }

    public static function supplierUpdateOrder(SupplierOrder $order, string $status): SupplierOrder
    {
        if (! in_array($status, SupplierOrder::SUPPLIER_TRANSITIONS[$order->status] ?? [], true)) {
            throw ApiError::invalid('That change isn’t possible for this order.', 'status');
        }
        $order->status = $status;
        $order->updated_at = now();
        $order->save();
        Activity::record('supplier_order', "{$order->supplier->name} marked {$order->reference} as {$status}", $order->store_id);

        return $order;
    }

    public static function shopCancelOrder(SupplierOrder $order): SupplierOrder
    {
        if ($order->status !== 'new') {
            throw ApiError::invalid('The supplier is already handling this order. Call them to change it.', 'status');
        }
        $order->status = 'cancelled';
        $order->updated_at = now();
        $order->save();
        Activity::record('supplier_order', "Order {$order->reference} to {$order->supplier->name} was cancelled", $order->store_id);

        return $order;
    }
}
