<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Api\ApiController;
use App\Services\Access;
use App\Services\Permissions as P;
use App\Services\SupplierAccounts;
use App\Support\Ctx;
use Illuminate\Http\Request;

/** /api/vendor/stores/{storeId}/supplier-accounts...: supplier balances (owners and managers). */
class SupplierAccountsController extends ApiController
{
    public function index()
    {
        Ctx::require(P::BUY_STOCK);

        return $this->json(SupplierAccounts::list(Ctx::store()));
    }

    public function create(Request $request)
    {
        Ctx::require(P::BUY_STOCK);

        return $this->json(SupplierAccounts::json(SupplierAccounts::save(Ctx::store(), $this->body($request)), true), 201);
    }

    public function show(string $storeId, string $accountId)
    {
        Ctx::require(P::BUY_STOCK);

        return $this->json(SupplierAccounts::json(SupplierAccounts::find(Ctx::store(), $accountId), true));
    }

    public function update(Request $request, string $storeId, string $accountId)
    {
        Ctx::require(P::BUY_STOCK);
        $store = Ctx::store();

        return $this->json(SupplierAccounts::json(SupplierAccounts::save($store, $this->body($request), SupplierAccounts::find($store, $accountId)), true));
    }

    /** {kind: bill|payment, amount, date?, dueOn?, note?} */
    public function addEntry(Request $request, string $storeId, string $accountId)
    {
        Ctx::require(P::BUY_STOCK);
        $store = Ctx::store();
        $account = SupplierAccounts::addEntry($store, SupplierAccounts::find($store, $accountId), $this->body($request), Access::currentUser());

        return $this->json(SupplierAccounts::json($account, true), 201);
    }

    public function destroy(string $storeId, string $accountId)
    {
        Ctx::require(P::BUY_STOCK);
        SupplierAccounts::delete(SupplierAccounts::find(Ctx::store(), $accountId));

        return response()->noContent();
    }
}
