<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminFinanceController;
use App\Http\Controllers\Api\AdminReviewsController;
use App\Http\Controllers\Api\AdminSuppliersController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\PaymentsController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\StorefrontController;
use App\Http\Controllers\Api\SupplierPortalController;
use App\Http\Controllers\Api\Vendor\AccountingController;
use App\Http\Controllers\Api\Vendor\CreditController;
use App\Http\Controllers\Api\Vendor\ShopController;
use App\Http\Controllers\Api\Vendor\SupplierAccountsController;
use App\Http\Controllers\Api\Vendor\SuppliersController;
use App\Http\Controllers\Api\Vendor\TeamController;
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
Route::get('suppliers', [PublicController::class, 'suppliers']);
Route::post('telemetry/error', [PublicController::class, 'browserError']);
Route::get('tls/check', [PublicController::class, 'tlsCheck']);
Route::get('uploads/{name}', [MediaController::class, 'show'])->where('name', '[A-Za-z0-9._-]+');

// Accounts
Route::post('auth/login', [AuthController::class, 'login']);
Route::post('stores', [AuthController::class, 'openStore']);
Route::post('supplier-signups', [AuthController::class, 'applyAsSupplier']);
Route::post('reviews', [PublicController::class, 'createReview']);
Route::get('domains/resolve', [PublicController::class, 'resolveDomain']);
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
    Route::post('products/{productId}/transfer', 'transferStock');
    Route::get('sales', 'sales');
    Route::post('sales', 'createSale');
    Route::patch('sales/{saleId}', 'updateSale');
    Route::get('customers', 'customers');
    Route::post('customers', 'createCustomer');
    Route::get('customers/{customerId}', 'customer');
    Route::post('customers/{customerId}/payments', 'customerPayment');
    Route::get('quotes', 'quotes');
    Route::post('quotes', 'saveQuotation');
    Route::patch('quotes/{quoteId}', 'updateQuote');
    Route::get('payouts', 'payouts');
    Route::get('activity', 'activity');
});
Route::prefix('vendor/stores/{storeId}')->middleware(['topline.user', 'topline.store'])->group(function () {
    Route::get('suppliers', [SuppliersController::class, 'index']);
    Route::get('suppliers/{supplier}', [SuppliersController::class, 'show']);
    Route::post('suppliers/{supplier}/orders', [SuppliersController::class, 'placeOrder']);
    Route::get('supplier-orders', [SuppliersController::class, 'orders']);
    Route::patch('supplier-orders/{orderId}', [SuppliersController::class, 'cancelOrder']);
    Route::get('accounting', [AccountingController::class, 'summary']);
    Route::get('accounting/export', [AccountingController::class, 'export']);
    Route::get('expenses', [AccountingController::class, 'expenses']);
    Route::post('expenses', [AccountingController::class, 'addExpense']);
    Route::delete('expenses/{expenseId}', [AccountingController::class, 'deleteExpense'])->whereNumber('expenseId');
    Route::get('staff', [TeamController::class, 'staff']);
    Route::post('staff', [TeamController::class, 'addStaff']);
    Route::patch('staff/{memberId}', [TeamController::class, 'updateStaff']);
    Route::delete('staff/{memberId}', [TeamController::class, 'removeStaff']);
    Route::post('staff/{memberId}/reset-password', [TeamController::class, 'resetStaffPassword']);
    Route::post('branches', [TeamController::class, 'addBranch']);
    Route::patch('branches/{branchId}', [TeamController::class, 'updateBranch']);
    Route::delete('branches/{branchId}', [TeamController::class, 'removeBranch']);
    Route::get('supplier-accounts', [SupplierAccountsController::class, 'index']);
    Route::post('supplier-accounts', [SupplierAccountsController::class, 'create']);
    Route::get('supplier-accounts/{accountId}', [SupplierAccountsController::class, 'show']);
    Route::patch('supplier-accounts/{accountId}', [SupplierAccountsController::class, 'update']);
    Route::delete('supplier-accounts/{accountId}', [SupplierAccountsController::class, 'destroy']);
    Route::post('supplier-accounts/{accountId}/entries', [SupplierAccountsController::class, 'addEntry']);
    Route::get('credit-requests', [CreditController::class, 'index']);
    Route::post('credit-requests', [CreditController::class, 'create']);
});

// Supplier accounts (Connect to suppliers)
Route::prefix('supplier')->middleware(['topline.user', 'topline.supplier'])->controller(SupplierPortalController::class)->group(function () {
    Route::get('me', 'me');
    Route::put('profile', 'updateProfile');
    Route::get('products', 'products');
    Route::post('products', 'createProduct');
    Route::put('products/{productId}', 'updateProduct');
    Route::delete('products/{productId}', 'deleteProduct');
    Route::get('orders', 'orders');
    Route::patch('orders/{orderId}', 'updateOrder');
});

// Topline admin
Route::prefix('admin')->middleware(['topline.user', 'topline.admin'])->controller(AdminController::class)->group(function () {
    Route::get('overview', 'overview');
    Route::get('badges', 'badges');
    Route::get('stores', 'stores');
    Route::patch('stores/{storeId}', 'updateStore');
    Route::post('stores/{storeId}/reset-password', 'resetOwnerPassword');
    Route::get('activity', 'activity');
    Route::get('payments', 'payments');
    Route::get('payouts', 'payouts');
    Route::post('payouts/{payoutId}/retry', 'retryPayout')->whereNumber('payoutId');
    Route::get('plans', 'plans');
    Route::put('plans/{planId}', 'updatePlan');
    Route::put('site', 'updateSite');
    Route::get('incidents', 'incidents');
    Route::patch('incidents/{incidentId}', 'resolveIncident')->whereNumber('incidentId');
});
Route::prefix('admin')->middleware(['topline.user', 'topline.admin'])->group(function () {
    Route::get('suppliers', [AdminSuppliersController::class, 'index']);
    Route::get('suppliers/{supplierId}', [AdminSuppliersController::class, 'show']);
    Route::patch('suppliers/{supplierId}', [AdminSuppliersController::class, 'update']);
    Route::post('suppliers/{supplierId}/review', [AdminSuppliersController::class, 'review']);
    Route::post('suppliers/{supplierId}/reset-password', [AdminSuppliersController::class, 'resetPassword']);
    Route::get('supplier-orders', [AdminSuppliersController::class, 'orders']);
    Route::get('reviews', [AdminReviewsController::class, 'index']);
    Route::patch('reviews/{reviewId}', [AdminReviewsController::class, 'update']);
    Route::delete('reviews/{reviewId}', [AdminReviewsController::class, 'destroy']);
    Route::get('accounting', [AdminFinanceController::class, 'accounting']);
    Route::get('accounting/export', [AdminFinanceController::class, 'export']);
    Route::get('credit-requests', [AdminFinanceController::class, 'creditRequests']);
    Route::patch('credit-requests/{requestId}', [AdminFinanceController::class, 'updateCreditRequest']);
});
