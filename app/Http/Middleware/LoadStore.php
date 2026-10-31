<?php

namespace App\Http\Middleware;

use App\Services\Access;
use Closure;
use Illuminate\Http\Request;

/** /api/vendor/stores/{storeId}/...: the store's own staff, or a Topline admin ("topline.store"). */
class LoadStore
{
    public function handle(Request $request, Closure $next)
    {
        Access::loadStore($request->route('storeId'));

        return $next($request);
    }
}
