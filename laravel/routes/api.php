<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\PaymentsController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\StorefrontController;
use App\Http\Controllers\Api\Vendor\ShopController;
use Illuminate\Support\Facades\Route;

/*
| Every route the website uses, under /api. The full contract (with real examples) is in
| docs/API-CONTRACT.md and docs/api-examples.json.
*/

// Open
Route::get('health', [PublicController::class, 'health']);
Route::get('ping', [PublicController::class, 'ping']);
Route::get('plans', [PublicController::class, 'plans']);
Route::get('stores/check-slug', [PublicController::class, 'checkSlug']);
Route::get('site', [PublicController::class, 'site']);
Route::post('telemetry/error', [PublicController::class, 'browserError']);
Route::get('tls/check', [PublicController::class, 'tlsCheck']);
Route::get('uploads/{name}', [MediaController::class, 'show'])->where('name', '[A-Za-z0-9._-]+');

// Accounts
Route::post('auth/login', [AuthController::class, 'login']);
Route::post('stores', [AuthController::class, 'openStore']);
Route::middleware('topline.user')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('auth/password', [AuthController::class, 'changePassword'])->name('auth.password');
    Route::post('uploads', [MediaController::class, 'upload']);
});

// Paying for a plan; the test phone; Paystack; background jobs over HTTP
Route::post('billing/subscription/pay', [BillingController::class, 'pay']);
Route::get('billing/payments/{reference}', [BillingController::class, 'status']);
Route::post('simulator/payments/{reference}', [PaymentsController::class, 'simulate']);
Route::post('webhooks/paystack', [PaymentsController::class, 'paystackWebhook']);
Route::match(['get', 'post'], 'cron/run', [PaymentsController::class, 'cron']);

// Shop pages (no login)
Route::prefix('storefront/{slug}')->group(function () {
    Route::get('/', [StorefrontController::class, 'show']);
    Route::get('products/{productSlug}', [StorefrontController::class, 'product']);
    Route::post('orders', [StorefrontController::class, 'placeOrder']);
    Route::get('orders/{orderId}/payment', [StorefrontController::class, 'orderPayment']);
    Route::post('quotes', [StorefrontController::class, 'requestQuote']);
});

// Shop admin: the store's own staff, or a Topline admin
Route::middleware('topline.user')->get('vendor/stores', [ShopController::class, 'myStores']);
Route::prefix('vendor/stores/{storeId}')->middleware(['topline.user', 'topline.store'])->controller(ShopController::class)->group(function () {
    Route::get('/', 'show');
    Route::patch('/', 'update');
    Route::get('dashboard', 'dashboard');
    Route::get('products', 'products');
    Route::post('products', 'createProduct');
    Route::put('products/{productId}', 'updateProduct');
    Route::delete('products/{productId}', 'deleteProduct');
    Route::post('products/{productId}/stock', 'adjustStock');
    Route::get('sales', 'sales');
    Route::post('sales', 'createSale');
    Route::patch('sales/{saleId}', 'updateSale');
    Route::get('customers', 'customers');
    Route::post('customers', 'createCustomer');
    Route::get('customers/{customerId}', 'customer');
    Route::post('customers/{customerId}/payments', 'customerPayment');
    Route::get('quotes', 'quotes');
    Route::patch('quotes/{quoteId}', 'updateQuote');
    Route::get('payouts', 'payouts');
    Route::get('activity', 'activity');
});

// Topline admin
Route::prefix('admin')->middleware(['topline.user', 'topline.admin'])->controller(AdminController::class)->group(function () {
    Route::get('overview', 'overview');
    Route::get('stores', 'stores');
    Route::patch('stores/{storeId}', 'updateStore');
    Route::post('stores/{storeId}/reset-password', 'resetOwnerPassword');
    Route::get('activity', 'activity');
    Route::get('payments', 'payments');
    Route::get('payouts', 'payouts');
    Route::post('payouts/{payoutId}/retry', 'retryPayout')->whereNumber('payoutId');
    Route::get('plans', 'plans');
    Route::put('site', 'updateSite');
    Route::get('incidents', 'incidents');
    Route::patch('incidents/{incidentId}', 'resolveIncident')->whereNumber('incidentId');
});
