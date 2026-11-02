<?php

namespace App\Services;

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
use Illuminate\Support\Facades\DB;

/**
 * Connect to suppliers: profiles, products and orders.
 *
 * Review rule: until a Topline admin first approves a profile or product, the supplier edits it
 * directly. After approval, the supplier's edits wait in `draft` and shops keep seeing the
 * approved version until an admin approves the change. Admin edits go live at once.
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
    private const PROFILE_FIELDS = ['name', 'logo', 'description', 'categories', 'location', 'phone', 'email', 'website',
        'min_order', 'delivery_info', 'payment_options', 'payment_instructions'];
    private const PRODUCT_FIELDS = ['name', 'description', 'unit', 'price', 'min_qty', 'in_stock', 'image'];

    // ---------------------------------------------------------------- creating suppliers (admin)

    /** @return array{0: Supplier, 1: string} the supplier and the login's temporary password */
    public static function create(array $data, User $admin): array
    {
        $name = Input::text($data, 'name', true, 120, 'Enter the supplier’s name.');
        $contact = Input::text($data, 'contactName', true, 120, 'Enter the name of the person who will log in.');
        $phone = Phone::normalize($data['phone'] ?? null) ?? throw ApiError::invalid('Enter a mobile number like 0712 345 678.', 'phone');
        $email = Passwords::normalizeEmail($data['email'] ?? null);
        if (User::where('phone', $phone)->exists()) {
            throw ApiError::invalid('That number already has a Topline login. Use a different number.', 'phone');
        }
        if ($email && User::where('email', $email)->exists()) {
            throw ApiError::invalid('That email already has a Topline login.', 'email');
        }
        $temp = Passwords::temporary();

        $supplier = DB::transaction(function () use ($name, $contact, $phone, $email, $temp) {
            $user = new User(['name' => $contact, 'phone' => $phone, 'email' => $email, 'role' => User::SUPPLIER, 'must_change_password' => true]);
            Passwords::set($user, $temp);
            $user->save();

            return Supplier::create([
                'user_id' => $user->id, 'slug' => self::uniqueSlug($name), 'name' => $name, 'description' => '',
                'phone' => $phone, 'email' => $email ?? '', 'payment_options' => [], 'status' => Supplier::PENDING,
            ]);
        });
        Activity::record('supplier_added', "{$admin->name} added supplier {$name}", null, $admin);

        return [$supplier, $temp];
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

    // ---------------------------------------------------------------- profile

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

    /** The supplier edits their own profile: live until first approval, a draft after that. */
    public static function submitProfile(Supplier $supplier, array $data, User $by): Supplier
    {
        $fields = self::validateProfile($data);
        if ($supplier->approved_at === null) {
            $supplier->fill($fields);
            $supplier->draft = null;
        } else {
            $supplier->draft = $fields;
        }
        $supplier->review_status = 'pending';
        $supplier->review_note = '';
        $supplier->save();
        Activity::record('supplier_review', "{$supplier->name} sent profile changes for approval", null, $by);

        return $supplier;
    }

    /** A Topline admin edits the profile: live at once. */
    public static function adminUpdateProfile(Supplier $supplier, array $data, User $admin): Supplier
    {
        $supplier->fill(self::validateProfile($data));
        $supplier->save();
        Activity::record('supplier_review', "{$admin->name} edited supplier {$supplier->name}", null, $admin);

        return $supplier;
    }

    public static function reviewProfile(Supplier $supplier, string $action, string $note, User $admin): Supplier
    {
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw ApiError::invalid('Choose approve or reject.', 'action');
        }
        if ($action === 'approve') {
            if ($supplier->draft) {
                $supplier->fill(array_intersect_key($supplier->draft, array_flip(self::PROFILE_FIELDS)));
            }
            if (trim($supplier->description) === '' || ! $supplier->payment_options) {
                throw ApiError::invalid('This profile isn’t complete yet: it needs a description and payment options.', 'action');
            }
            $supplier->draft = null;
            $supplier->review_status = '';
            $supplier->review_note = '';
            if ($supplier->status === Supplier::PENDING) {
                $supplier->status = Supplier::APPROVED;
            }
            $supplier->approved_at ??= now();
            $summary = "{$admin->name} approved {$supplier->name}’s profile";
        } else {
            $supplier->review_status = 'rejected';
            $supplier->review_note = mb_substr($note, 0, 300);
            $summary = "{$admin->name} sent {$supplier->name}’s profile back for changes";
        }
        $supplier->save();
        Activity::record('supplier_review', $summary, null, $admin);

        return $supplier;
    }

    public static function setListed(Supplier $supplier, bool $listed, User $admin): Supplier
    {
        if ($listed && $supplier->approved_at === null) {
            throw ApiError::invalid('Approve the profile first.', 'status');
        }
        $supplier->status = $listed ? Supplier::APPROVED : Supplier::HIDDEN;
        $supplier->save();
        Activity::record('supplier_review', "{$admin->name} ".($listed ? 'listed' : 'hid')." supplier {$supplier->name}", null, $admin);

        return $supplier;
    }

    // ---------------------------------------------------------------- products

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

    public static function saveProduct(Supplier $supplier, ?SupplierProduct $product, array $data, User $by): SupplierProduct
    {
        $fields = self::validateProduct($data);
        if (! $product) {
            if ($supplier->products()->count() >= self::MAX_PRODUCTS) {
                throw ApiError::invalid('You’ve reached '.self::MAX_PRODUCTS.' products. Remove some first.', 'name');
            }
            $product = new SupplierProduct(['supplier_id' => $supplier->id]);
        }
        if ($product->approved_at === null) {
            $product->fill($fields);
            $product->status = SupplierProduct::PENDING;
            $product->draft = null;
        } else {
            $product->draft = $fields; // shops keep seeing the approved version until this is approved
        }
        $product->review_note = '';
        $product->save();
        Activity::record('supplier_review', "{$supplier->name} sent “{$fields['name']}” for approval", null, $by);

        return $product;
    }

    public static function reviewProduct(SupplierProduct $product, string $action, string $note, User $admin): SupplierProduct
    {
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw ApiError::invalid('Choose approve or reject.', 'action');
        }
        if ($action === 'approve') {
            if ($product->draft) {
                $product->fill(array_intersect_key($product->draft, array_flip(self::PRODUCT_FIELDS)));
            }
            $product->draft = null;
            $product->status = SupplierProduct::APPROVED;
            $product->review_note = '';
            $product->approved_at ??= now();
        } elseif ($product->draft) {
            $product->draft = null; // the approved version stays
            $product->review_note = 'Change not approved: '.mb_substr($note, 0, 270);
        } else {
            $product->status = SupplierProduct::REJECTED;
            $product->review_note = mb_substr($note, 0, 300);
        }
        $product->save();
        $verb = $action === 'approve' ? 'approved' : 'turned down';
        Activity::record('supplier_review', "{$admin->name} {$verb} “{$product->name}” from {$product->supplier->name}", null, $admin);

        return $product;
    }

    /** Things waiting for a Topline admin: profiles and products. */
    public static function pendingReviews(): int
    {
        return Supplier::where('review_status', 'pending')->count()
            + SupplierProduct::where(fn ($q) => $q->where('status', SupplierProduct::PENDING)->orWhereNotNull('draft'))->count();
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
        $products = SupplierProduct::where('supplier_id', $supplier->id)->where('status', SupplierProduct::APPROVED)->get()->keyBy('id');
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
