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
use App\Services\Branches;
use App\Services\Catalog;
use App\Services\Customers;
use App\Services\Dashboard;
use App\Services\Orders;
use App\Services\Permissions as P;
use App\Services\Plans;
use App\Services\Products;
use App\Services\Quotations;
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
        $store = Ctx::store();

        return $this->json([...Json::storePrivate($store), 'myRole' => Ctx::storeRole() ?? 'platform_admin', 'permissions' => Ctx::permissions(),
            ...Branches::summary($store), 'limits' => [
                'products' => Plans::limit($store, 'products'), 'staff' => Plans::limit($store, 'staff'),
                'branches' => Plans::limit($store, 'branches'), 'customDomain' => Plans::limit($store, 'customDomain'),
            ]]);
    }

    public function update(Request $request)
    {
        Ctx::require(P::MANAGE_SETTINGS);

        return $this->json(Json::storePrivate(Stores::updateProfile(Ctx::store(), $this->body($request))));
    }

    /** ?branchId= one branch; empty = the whole shop (staff tied to a branch always see theirs). */
    public function dashboard(Request $request)
    {
        Ctx::require(P::VIEW_DASHBOARD);
        $requested = (string) $request->query('branchId', '');
        $branch = Branches::forCaller(Ctx::store(), $requested);
        $whole = $requested === '' && $branch->is_main && ! $this->tiedToBranch();

        return $this->json(Dashboard::build(Ctx::store(), Ctx::can(P::VIEW_COSTS), $whole ? null : $branch));
    }

    private function tiedToBranch(): bool
    {
        $user = Access::currentUser();

        return ! $user->isPlatformAdmin() && (bool) StoreMember::where('store_id', Ctx::store()->id)->where('user_id', $user->id)->value('branch_id');
    }

    // ---- Products & stock ----

    /** ?branchId= : "stock" is the stock at that branch. Owners and managers also get every branch's stock. */
    public function products(Request $request)
    {
        Ctx::require(P::VIEW_PRODUCTS);
        $store = Ctx::store();
        $branch = Branches::forCaller($store, $request->query('branchId'));
        $stock = Branches::stockMap($store, $branch);
        $rows = Product::where('store_id', $store->id)->orderByDesc('created_at')->get();
        $data = ['products' => $rows->map(fn ($p) => Json::product($p, Ctx::can(P::VIEW_COSTS), $stock[$p->id] ?? 0))->all(),
            'categories' => Catalog::CATEGORIES, 'branchId' => $branch->id];
        if (Ctx::can(P::VIEW_COSTS) && Branches::all($store)->count() > 1) {
            $data['stockByBranch'] = Branches::stockByBranch($store);
        }

        return $this->json($data);
    }

    public function createProduct(Request $request)
    {
        Ctx::require(P::MANAGE_PRODUCTS);

        $data = $this->body($request);
        $branch = Branches::forCaller(Ctx::store(), $data['branchId'] ?? null);
        $product = Products::save(Ctx::store(), $data, Access::currentUser(), null, $branch);

        return $this->json(Json::product($product, true, Branches::stockAt($product, $branch)), 201);
    }

    public function updateProduct(Request $request, string $storeId, string $productId)
    {
        Ctx::require(P::MANAGE_PRODUCTS);
        $product = Products::find(Ctx::store(), $productId);
        $data = $this->body($request);
        $branch = Branches::forCaller(Ctx::store(), $data['branchId'] ?? null);
        $product = Products::save(Ctx::store(), $data, Access::currentUser(), $product, $branch);

        return $this->json(Json::product($product, true, Branches::stockAt($product, $branch)));
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
        $data = $this->body($request);
        $delta = Input::integer($data, 'delta', required: true, message: 'Enter how much to add or remove.');
        $branch = Branches::forCaller(Ctx::store(), $data['branchId'] ?? null);
        DB::transaction(function () use ($product, $delta, $branch) {
            $before = Branches::stockAt($product, $branch);
            Stock::adjust($product, $delta, 'adjust', 'stock button', Access::currentUser()->id, $branch);
            $where = $branch->is_main ? '' : " at {$branch->name}";
            Activity::record('stock_adjusted', "Stock of {$product->name}{$where} changed {$before} → ".($before + $delta), $product->store_id,
                data: ['productId' => $product->id, 'before' => $before, 'after' => $before + $delta, 'branchId' => $branch->id]);
        });

        return $this->json(Json::product($product->refresh(), Ctx::can(P::VIEW_COSTS), Branches::stockAt($product, $branch)));
    }

    /** {fromBranchId, toBranchId, qty}: move stock between branches (owners and managers). */
    public function transferStock(Request $request, string $storeId, string $productId)
    {
        Ctx::require(P::MANAGE_PRODUCTS);
        if ($this->tiedToBranch()) {
            throw ApiError::forbidden('Ask the owner or a manager who works at every branch to move stock.');
        }
        $store = Ctx::store();
        $product = Products::find($store, $productId);
        $data = $this->body($request);
        $from = Branches::find($store, $data['fromBranchId'] ?? null);
        $to = Branches::find($store, $data['toBranchId'] ?? null);
        $qty = Input::integer($data, 'qty', required: true, minimum: 1, message: 'Enter how many to move.');
        Stock::transfer($product, $from, $to, $qty, Access::currentUser()->id);
        Activity::record('stock_adjusted', "Moved {$qty} {$product->unit} of {$product->name} from {$from->name} to {$to->name}", $store->id,
            data: ['productId' => $product->id]);

        return $this->json(['stockByBranch' => Branches::stockByBranch($store)]);
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
        if ($request->query('branchId') || $this->tiedToBranch()) {
            $branch = Branches::forCaller(Ctx::store(), $request->query('branchId'));
            $query->where(fn ($q) => $branch->is_main ? $q->where('branch_id', $branch->id)->orWhereNull('branch_id') : $q->where('branch_id', $branch->id));
        }
        $rows = $query->orderByDesc('created_at')->orderByDesc('number')->limit($this->limit($request, 200))->get();

        return $this->json($rows->map(fn ($s) => Json::sale($s, Ctx::can(P::VIEW_COSTS)))->all());
    }

    public function createSale(Request $request)
    {
        Ctx::require(P::SELL);
        $data = $this->body($request);
        $store = Ctx::store();
        $quote = ($data['quoteId'] ?? null) ? Quotations::find($store, $data['quoteId']) : null;
        $sale = Sales::counterSale($store, Access::currentUser(), $data['items'] ?? null, $data['method'] ?? null, $data['customerId'] ?? null,
            Branches::forCaller($store, $data['branchId'] ?? null), $quote);

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

    // ---- Quotations: requests from the store page, and ones priced at the till ----

    public function saveQuotation(Request $request)
    {
        Ctx::require(P::SELL);

        return $this->json(Json::quote(Quotations::save(Ctx::store(), $this->body($request), Access::currentUser())), 201);
    }

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
