<?php

namespace App\Http\Middleware;

use App\Models\Supplier;
use App\Services\Access;
use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;

/** Supplier accounts only ("topline.supplier"); loads the caller's own supplier profile. */
class RequireSupplier
{
    public function handle(Request $request, Closure $next)
    {
        $user = Access::currentUser();
        $supplier = $user->isSupplier() ? Supplier::where('user_id', $user->id)->first() : null;
        if (! $supplier) {
            throw ApiError::forbidden('Only supplier accounts can open this.');
        }
        $request->attributes->set('topline.supplier', $supplier);

        return $next($request);
    }
}
