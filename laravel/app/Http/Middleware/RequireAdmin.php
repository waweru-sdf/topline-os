<?php

namespace App\Http\Middleware;

use App\Services\Access;
use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;

/** Topline staff only ("topline.admin"). */
class RequireAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! Access::currentUser()->isPlatformAdmin()) {
            throw ApiError::forbidden('Only Topline admins can open this.');
        }

        return $next($request);
    }
}
