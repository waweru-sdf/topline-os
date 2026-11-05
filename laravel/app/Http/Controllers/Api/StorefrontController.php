<?php

namespace App\Http\Controllers\Api;

use App\Http\Presenters\Json;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\Store;
use App\Services\Branches;
use App\Services\Catalog;
use App\Services\Monitoring;
use App\Services\Orders;
use App\Support\ApiError;
use App\Support\Input;
use App\Support\Phone;
use Illuminate\Http\Request;

/** The public shop pages: catalogue, orders, quotes. No login needed. */
class StorefrontController extends ApiController
{
    private function store(string $slug): Store
    {
        return Store::where('slug', $slug)->first() ?? throw ApiError::notFound("There's no store at \"{$slug}\".");
    }

    /** ?branch=<slug>: that branch's page (its stock, contacts and hours). */
    public function show(Request $request, string $slug)
    {
        $store = $this->store($slug);
        $branch = Branches::forPage($store, $request->query('branch'));
        Monitoring::countView('store', $store->id);
        if (! $store->isOpen()) { // the website shows a "closed for now" page
            return $this->json(['store' => Json::storePublic($store, $branch), 'categories' => [], 'products' => [], 'branches' => []]);
        }
        $products = Product::where('store_id', $store->id)->where('published', true)->orderBy('category_id')->orderBy('name')->get();
        $stock = Branches::stockMap($store, $branch);
        $used = $products->pluck('category_id')->unique()->all();

        return $this->json([
            'store' => Json::storePublic($store, $branch),
            'categories' => array_values(array_filter(Catalog::CATEGORIES, fn ($c) => in_array($c['id'], $used, true))),
            'products' => $products->map(fn ($p) => Json::product($p, false, $stock[$p->id] ?? 0))->all(), // never includes buying prices
            'branches' => Branches::publicList($store),
        ]);
    }

    public function product(Request $request, string $slug, string $productSlug)
    {
        $store = $this->store($slug);
        $branch = Branches::forPage($store, $request->query('branch'));
        $product = Product::where('store_id', $store->id)->where('slug', $productSlug)->where('published', true)->first();
        if (! $product || ! $store->isOpen()) {
            throw ApiError::notFound('This product is no longer listed.');
        }
        Monitoring::countView('product', $store->id);

        return $this->json(['store' => Json::storePublic($store, $branch), 'product' => Json::product($product, false, Branches::stockAt($product, $branch)),
            'category' => Catalog::find($product->category_id)]);
    }

    public function placeOrder(Request $request, string $slug)
    {
        $data = $this->body($request);
        $store = $this->store($slug);
        $branch = ($data['branch'] ?? '') !== '' ? Branches::forPage($store, $data['branch']) : null; // a branch's page: its stock
        [$order, $payment] = Orders::place($store, is_array($data['customer'] ?? null) ? $data['customer'] : [],
            $data['items'] ?? null, is_array($data['delivery'] ?? null) ? $data['delivery'] : null,
            ($data['paymentMode'] ?? null) ?: 'prompt', $data['mpesaCode'] ?? null, $branch);

        return $this->json([...Json::sale($order), 'payment' => $payment ? Json::payment($payment) : null], 201);
    }

    public function orderPayment(string $slug, string $orderId)
    {
        [$order, $payment] = Orders::paymentStatus($this->store($slug), $orderId);
        $status = ! in_array($order->status, [Sale::AWAITING_PAYMENT, Sale::CANCELLED], true) ? 'paid'
            : ($order->status === Sale::CANCELLED ? 'failed' : 'pending');

        return $this->json([
            'status' => $status,
            'mpesaRef' => $order->mpesa_ref,
            'failureReason' => $payment?->failure_reason,
            'payment' => $payment ? Json::payment($payment) : null,
            'order' => Json::sale($order),
        ]);
    }

    public function requestQuote(Request $request, string $slug)
    {
        $store = $this->store($slug);
        $data = $this->body($request);
        $phone = Phone::normalize($data['phone'] ?? null);
        if (! $phone) {
            throw ApiError::invalid('Enter a phone number we can reach you on.', 'phone');
        }
        $quote = Quote::create([
            'store_id' => $store->id,
            'reference' => 'Q-'.random_int(10000, 99999),
            'name' => Input::text($data, 'name', required: true, maxLength: 120, message: 'Enter your name.'),
            'phone' => $phone,
            'items' => Input::text($data, 'items', required: true, maxLength: 4000, message: 'List what you need.'),
            'site' => Input::text($data, 'site'),
            'status' => 'new',
        ]);

        return $this->json(['ok' => true, 'reference' => $quote->reference], 201);
    }
}
