<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Api\ApiController;
use App\Http\Presenters\SupplierJson;
use App\Models\CreditRequest;
use App\Services\Access;
use App\Services\CreditRequests;
use App\Services\Permissions;
use App\Support\Ctx;
use Illuminate\Http\Request;

/** Shop admin: ask for stock credit. The shop owner only (it's a financial commitment). */
class CreditController extends ApiController
{
    public function index()
    {
        Ctx::require(Permissions::MANAGE_BILLING);
        $rows = CreditRequest::where('store_id', Ctx::store()->id)->orderByDesc('created_at')->get();

        return $this->json($rows->map(fn ($c) => SupplierJson::creditRequest($c))->values());
    }

    public function create(Request $request)
    {
        Ctx::require(Permissions::MANAGE_BILLING);
        $c = CreditRequests::create(Ctx::store(), $this->body($request), Access::currentUser());

        return $this->json(SupplierJson::creditRequest($c), 201);
    }
}
