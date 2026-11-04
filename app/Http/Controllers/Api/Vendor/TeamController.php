<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Api\ApiController;
use App\Http\Presenters\Json;
use App\Services\Access;
use App\Services\Branches;
use App\Services\Domains;
use App\Services\Permissions as P;
use App\Services\Staff;
use App\Support\Ctx;
use Illuminate\Http\Request;

/** /api/vendor/stores/{storeId}/...: staff logins, branches and the shop's own domain. Owners only. */
class TeamController extends ApiController
{
    public function staff()
    {
        Ctx::require(P::MANAGE_STAFF);

        return $this->json(Staff::list(Ctx::store()));
    }

    /** {name, phone, role: manager|cashier, branchId?} → {member, temporaryPassword} (shown once). */
    public function addStaff(Request $request)
    {
        Ctx::require(P::MANAGE_STAFF);

        return $this->json(Staff::add(Ctx::store(), $this->body($request), Access::currentUser()), 201);
    }

    public function updateStaff(Request $request, string $storeId, string $memberId)
    {
        Ctx::require(P::MANAGE_STAFF);
        $store = Ctx::store();

        return $this->json(Staff::update($store, Staff::find($store, $memberId), $this->body($request), Access::currentUser()));
    }

    public function removeStaff(string $storeId, string $memberId)
    {
        Ctx::require(P::MANAGE_STAFF);
        $store = Ctx::store();
        Staff::remove($store, Staff::find($store, $memberId), Access::currentUser());

        return response()->noContent();
    }

    public function resetStaffPassword(string $storeId, string $memberId)
    {
        Ctx::require(P::MANAGE_STAFF);
        $store = Ctx::store();

        return $this->json(Staff::resetPassword($store, Staff::find($store, $memberId), Access::currentUser()));
    }

    public function addBranch(Request $request)
    {
        Ctx::require(P::MANAGE_STAFF);

        return $this->json(Branches::json(Branches::create(Ctx::store(), $this->body($request), Access::currentUser())), 201);
    }

    public function updateBranch(Request $request, string $storeId, string $branchId)
    {
        Ctx::require(P::MANAGE_STAFF);
        $store = Ctx::store();

        return $this->json(Branches::json(Branches::update($store, Branches::find($store, $branchId), $this->body($request))));
    }

    public function removeBranch(string $storeId, string $branchId)
    {
        Ctx::require(P::MANAGE_STAFF);
        $store = Ctx::store();
        Branches::delete($store, Branches::find($store, $branchId), Access::currentUser());

        return response()->noContent();
    }

    /** {domain}: empty removes it. */
    public function setDomain(Request $request)
    {
        Ctx::require(P::MANAGE_SETTINGS);

        return $this->json(Json::storePrivate(Domains::set(Ctx::store(), $this->body($request)['domain'] ?? null, Access::currentUser())));
    }
}
