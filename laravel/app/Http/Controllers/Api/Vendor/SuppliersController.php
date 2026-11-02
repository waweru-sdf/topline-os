<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Api\ApiController;
use App\Http\Presenters\SupplierJson;
use App\Models\SupplierOrder;
use App\Models\SupplierProduct;
use App\Services\Access;
use App\Services\Permissions;
use App\Services\Suppliers;
use App\Support\ApiError;
use App\Support\Ctx;
use Illuminate\Http\Request;

/** Shop admin: browse listed suppliers and order from them. Owners and managers only. */
class SuppliersController extends ApiController
{
    public function index()
    {
        Ctx::require(Permissions::BUY_STOCK);
        $counts = Suppliers::connectedCounts();
        $productCounts = SupplierProduct::selectRaw('supplier_id, COUNT(*) as n')->groupBy('supplier_id')->pluck('n', 'supplier_id');

        return $this->json(Suppliers::listed()->map(fn ($s) => [
            ...SupplierJson::forShop($s, $counts[$s->id] ?? 0),
            'productCount' => (int) ($productCounts[$s->id] ?? 0),
        ])->values());
    }

    public function show(string $storeId, string $supplier)
    {
        Ctx::require(Permissions::BUY_STOCK);
        $s = Suppliers::findListed($supplier);
        $products = $s->products()->get();

        return $this->json(SupplierJson::forShop($s, Suppliers::connectedCounts()[$s->id] ?? 0, $products));
    }

    public function placeOrder(Request $request, string $storeId, string $supplier)
    {
        Ctx::require(Permissions::BUY_STOCK);
        $order = Suppliers::placeOrder(Ctx::store(), Suppliers::findListed($supplier), $this->body($request), Access::currentUser());

        return $this->json(SupplierJson::order($order->load('supplier'), 'shop'), 201);
    }

    public function orders(Request $request)
    {
        Ctx::require(Permissions::BUY_STOCK);
        $orders = SupplierOrder::with('supplier')->where('store_id', Ctx::store()->id)->orderByDesc('created_at')
            ->limit($this->limit($request, 100))->get();

        return $this->json($orders->map(fn ($o) => SupplierJson::order($o, 'shop'))->values());
    }

    public function cancelOrder(Request $request, string $storeId, string $orderId)
    {
        Ctx::require(Permissions::BUY_STOCK);
        $order = SupplierOrder::where('store_id', Ctx::store()->id)->find($orderId) ?? throw ApiError::notFound('That order doesn’t exist.');
        if (($this->body($request)['status'] ?? null) !== 'cancelled') {
            throw ApiError::invalid('You can only cancel an order here.', 'status');
        }

        return $this->json(SupplierJson::order(Suppliers::shopCancelOrder($order)->load('supplier'), 'shop'));
    }
}
