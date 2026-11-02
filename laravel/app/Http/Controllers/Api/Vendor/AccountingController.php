<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Api\ApiController;
use App\Http\Presenters\SupplierJson;
use App\Services\Access;
use App\Services\Accounting;
use App\Services\Permissions;
use App\Support\Ctx;
use Illuminate\Http\Request;

/** Shop admin: the shop's books. Owners and managers (they already see buying prices and profit). */
class AccountingController extends ApiController
{
    public function summary(Request $request)
    {
        Ctx::require(Permissions::MANAGE_ACCOUNTS);

        return $this->json(Accounting::shopSummary(Ctx::store(), $request->query('month')));
    }

    public function expenses(Request $request)
    {
        Ctx::require(Permissions::MANAGE_ACCOUNTS);
        [, , $month] = Accounting::month($request->query('month'));

        return $this->json(Accounting::expenses(Ctx::store(), $month)->map(fn ($e) => SupplierJson::expense($e))->values());
    }

    public function addExpense(Request $request)
    {
        Ctx::require(Permissions::MANAGE_ACCOUNTS);

        return $this->json(SupplierJson::expense(Accounting::addExpense(Ctx::store(), $this->body($request), Access::currentUser())), 201);
    }

    public function deleteExpense(string $storeId, string $expenseId)
    {
        Ctx::require(Permissions::MANAGE_ACCOUNTS);
        Accounting::deleteExpense(Ctx::store(), $expenseId, Access::currentUser());

        return response()->noContent();
    }

    public function export(Request $request)
    {
        Ctx::require(Permissions::MANAGE_ACCOUNTS);
        [$name, $csv] = Accounting::shopCsv(Ctx::store(), (string) $request->query('type', 'sales'), $request->query('month'));

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}\"",
            'Cache-Control' => 'no-store']);
    }
}
