<?php

namespace App\Http\Middleware;

use App\Services\Access;
use Closure;
use Illuminate\Http\Request;

/** Any logged-in user ("topline.user"). */
class RequireUser
{
    public function handle(Request $request, Closure $next)
    {
        Access::currentUser();

        return $next($request);
    }
}
