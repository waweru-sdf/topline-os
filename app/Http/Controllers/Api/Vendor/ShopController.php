<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Api\ApiController;
use App\Http\Presenters\Json;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Payout;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\Store;
use App\Models\StoreMember;
use App\Services\Access;
use App\Services\Activity;
use App\Services\Catalog;
use App\Services\Customers;
use App\Services\Dashboard;
use App\Services\Orders;
use App\Services\Permissions as P;
use App\Services\Products;
use App\Services\Sales;
use App\Services\Stock;
use App\Services\Stores;
use App\Support\ApiError;
use App\Support\Ctx;
use App\Support\Input;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The shop admin: /api/vendor/stores/{storeId}/...
 * Every route here runs after LoadStore: the store's own owner/staff, or a Topline admin.
 */
class ShopController extends ApiController
{
    /** Stores the caller can open: their own, or every store for Topline admins. */
    public function myStores()
    {
        $user = Access::currentUser();
        if ($user->isPlatformAdmin()) {
            return $this->json(Store::orderBy('name')->get()->map(fn ($s) => [...Json::storePrivate($s), 'myRole' => 'platform_admin'])->all());
        }
        $rows = StoreMember::with('store')->where('user_id', $user->id)->get();

        return $this->json($rows->map(fn ($m) => [...Json::storePrivate($m->store), 'myRole' => $m->role])->all());
    }

    public function show()
    {
        return $this->json([...Json::storePrivate(Ctx::store()), 'myRole' => Ctx::storeRole() ?? 'platform_admin', 'permissions' => Ctx::permissions()]);
    }

    public function update(Request $request)
    {
        Ctx::require(P::MANAGE_SETTINGS);

        return $this->json(Json::storePrivate(Stores::updateProfile(Ctx::store(), $this->body($request))));
    }

    public function dashboard()
    {
        Ctx::require(P::VIEW_DASHBOARD);

        return $this->json(Dashboard::build(Ctx::store(), Ctx::can(P::VIEW_COSTS)));
    }

    // ---- Products & stock ----

    public function products()
    {
        Ctx::require(P::VIEW_PRODUCTS);
        $rows = Product::where('store_id', Ctx::store()->id)->orderByDesc('created_at')->get();

        return $this->json(['products' => $rows->map(fn ($p) => Json::product($p, Ctx::can(P::VIEW_COSTS)))->all(), 'categories' => Catalog::CATEGORIES]);
    }

    public function createProduct(Request $request)
    {
        Ctx::require(P::MANAGE_PRODUCTS);

        return $this->json(Json::product(Products::save(Ctx::store(), $this->body($request), Access::currentUser()), true), 201);
    }

    public function updateProduct(Request $request, string $storeId, string $productId)
    {
        Ctx::require(P::MANAGE_PRODUCTS);
        $product = Products::find(Ctx::store(), $productId);

        return $this->json(Json::product(Products::save(Ctx::store(), $this->body($request), Access::currentUser(), $product), true));
    }

    public function deleteProduct(string $storeId, string $productId)
    {
        Ctx::require(P::MANAGE_PRODUCTS);
        Products::delete(Ctx::store(), $productId);

        return response()->noContent();
    }

    public function adjustStock(Request $request, string $storeId, string $productId)
    {
        Ctx::require(P::MANAGE_PRODUCTS);
        $product = Products::find(Ctx::store(), $productId);
        $delta = Input::integer($this->body($request), 'delta', required: true, message: 'Enter how much to add or remove.');
        DB::transaction(function () use ($product, $delta) {
            $before = $product->stock;
            Stock::adjust($product, $delta, 'adjust', 'stock button', Access::currentUser()->id);
            Activity::record('stock_adjusted', "Stock of {$product->name} changed {$before} → {$product->stock}", $product->store_id,
                data: ['productId' => $product->id, 'before' => $before, 'after' => $product->stock]);
        });

        return $this->json(Json::product($product->refresh(), Ctx::can(P::VIEW_COSTS)));
    }

    // ---- Counter sales and online orders ----

    public function sales(Request $request)
    {
        Ctx::require(P::MANAGE_ORDERS);
        $query = Sale::with('items')->where('store_id', Ctx::store()->id)->where('status', '!=', Sale::AWAITING_PAYMENT);
        if (in_array($request->query('channel'), ['pos', 'online'], true)) {
            $query->where('channel', $request->query('channel'));
        }
        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }
        $rows = $query->orderByDesc('created_at')->orderByDesc('number')->limit($this->limit($request, 200))->get();

        return $this->json($rows->map(fn ($s) => Json::sale($s, Ctx::can(P::VIEW_COSTS)))->all());
    }

    public function createSale(Request $request)
    {
        Ctx::require(P::SELL);
        $data = $this->body($request);
        $sale = Sales::counterSale(Ctx::store(), Access::currentUser(), $data['items'] ?? null, $data['method'] ?? null, $data['customerId'] ?? null);

        return $this->json(Json::sale($sale, Ctx::can(P::VIEW_COSTS)), 201);
    }

    public function updateSale(Request $request, string $storeId, string $saleId)
    {
        Ctx::require(P::MANAGE_ORDERS);
        $order = Orders::updateStatus(Ctx::store(), $saleId, $this->body($request)['status'] ?? null, Access::currentUser());

        return $this->json(Json::sale($order, Ctx::can(P::VIEW_COSTS)));
    }

    // ---- Customers & credit ----

    public function customers()
    {
        Ctx::require(P::VIEW_CUSTOMERS);
        $rows = Customer::where('store_id', Ctx::store()->id)->orderByDesc('balance')->orderBy('name')->get();

        return $this->json($rows->map(fn ($c) => Json::customer($c))->all());
    }

    public function createCustomer(Request $request)
    {
        Ctx::require(P::MANAGE_CUSTOMERS);

        return $this->json(Json::customer(Customers::create(Ctx::store(), $this->body($request))), 201);
    }

    public function customer(string $storeId, string $customerId)
    {
        Ctx::require(P::VIEW_CUSTOMERS);
        $customer = Customer::find($customerId);
        if (! $customer || $customer->store_id !== Ctx::store()->id) {
            throw ApiError::notFound('Customer not found.');
        }

        return $this->json(Json::customer($customer));
    }

    public function customerPayment(Request $request, string $storeId, string $customerId)
    {
        Ctx::require(P::SELL); // cashiers take credit payments at the counter

        return $this->json(Json::customer(Customers::recordPayment(Ctx::store(), $customerId, $this->body($request), Access::currentUser())));
    }

    // ---- Quote requests from the storefront ----

    public function quotes()
    {
        Ctx::require(P::MANAGE_ORDERS);

        return $this->json(Quote::where('store_id', Ctx::store()->id)->orderByDesc('created_at')->get()->map(fn ($q) => Json::quote($q))->all());
    }

    public function updateQuote(Request $request, string $storeId, string $quoteId)
    {
        Ctx::require(P::MANAGE_ORDERS);
        $quote = Quote::find($quoteId);
        if (! $quote || $quote->store_id !== Ctx::store()->id) {
            throw ApiError::notFound('Quote not found.');
        }
        $status = $this->body($request)['status'] ?? null;
        if (! in_array($status, Quote::STATUSES, true)) {
            throw ApiError::invalid('Pick a status.', 'status');
        }
        $quote->update(['status' => $status]);

        return $this->json(Json::quote($quote));
    }

    // ---- Money received and activity (owners and Topline admins) ----

    public function payouts()
    {
        Ctx::require(P::MANAGE_SETTINGS);
        $rows = Payout::where('store_id', Ctx::store()->id)->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get();

        return $this->json($rows->map(fn ($p) => Json::payout($p))->all());
    }

    public function activity(Request $request)
    {
        Ctx::require(P::MANAGE_SETTINGS);
        $before = (string) $request->query('before', '');
        $rows = Activity::recent(Ctx::store()->id, $request->query('kind') ?: null, ctype_digit($before) ? (int) $before : null);

        return $this->json($rows->map(fn (ActivityLog $a) => Json::activity($a))->all());
    }
}
