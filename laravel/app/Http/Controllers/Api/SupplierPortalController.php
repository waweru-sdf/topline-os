<?php

namespace App\Http\Controllers\Api;

use App\Http\Presenters\SupplierJson;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierProduct;
use App\Services\Access;
use App\Services\Suppliers;
use App\Support\ApiError;
use Illuminate\Http\Request;

/** /api/supplier/...: a supplier's own profile, products and incoming orders. */
class SupplierPortalController extends ApiController
{
    private function supplier(Request $request): Supplier
    {
        return $request->attributes->get('topline.supplier');
    }

    private function product(Request $request, string $productId): SupplierProduct
    {
        return SupplierProduct::where('supplier_id', $this->supplier($request)->id)->find($productId)
            ?? throw ApiError::notFound('That product doesn’t exist.');
    }

    public function me(Request $request)
    {
        $s = $this->supplier($request);
        $orders = SupplierOrder::where('supplier_id', $s->id);

        return $this->json(SupplierJson::own($s, Suppliers::connectedCounts()[$s->id] ?? 0, [
            'newOrders' => (clone $orders)->where('status', 'new')->count(),
            'totalOrders' => (clone $orders)->count(),
            'paymentOptionChoices' => SupplierJson::paymentOptions(array_keys(Suppliers::PAYMENT_OPTIONS)),
        ]));
    }

    public function updateProfile(Request $request)
    {
        $s = Suppliers::submitProfile($this->supplier($request), $this->body($request), Access::currentUser());

        return $this->json(SupplierJson::own($s, Suppliers::connectedCounts()[$s->id] ?? 0));
    }

    public function products(Request $request)
    {
        return $this->json($this->supplier($request)->products()->get()->map(fn ($p) => SupplierJson::productOwn($p))->values());
    }

    public function createProduct(Request $request)
    {
        $p = Suppliers::saveProduct($this->supplier($request), null, $this->body($request), Access::currentUser());

        return $this->json(SupplierJson::productOwn($p), 201);
    }

    public function updateProduct(Request $request, string $productId)
    {
        $p = Suppliers::saveProduct($this->supplier($request), $this->product($request, $productId), $this->body($request), Access::currentUser());

        return $this->json(SupplierJson::productOwn($p));
    }

    public function deleteProduct(Request $request, string $productId)
    {
        $this->product($request, $productId)->delete();

        return response()->noContent();
    }

    public function orders(Request $request)
    {
        $orders = SupplierOrder::with('store')->where('supplier_id', $this->supplier($request)->id)
            ->orderByDesc('created_at')->limit($this->limit($request, 200))->get();

        return $this->json($orders->map(fn ($o) => SupplierJson::order($o, 'supplier'))->values());
    }

    public function updateOrder(Request $request, string $orderId)
    {
        $order = SupplierOrder::where('supplier_id', $this->supplier($request)->id)->find($orderId)
            ?? throw ApiError::notFound('That order doesn’t exist.');
        $order = Suppliers::supplierUpdateOrder($order, (string) ($this->body($request)['status'] ?? ''));

        return $this->json(SupplierJson::order($order->load('store'), 'supplier'));
    }
}
