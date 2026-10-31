<?php

namespace App\Http\Middleware;

use App\Services\Monitoring;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Times and counts every API request for System health, after the response has been sent. */
class TrackRequests
{
    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('topline.started', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->isMethod('OPTIONS') || $request->is('api/uploads/*') || $request->is('api/ping')) {
            return;
        }
        $started = $request->attributes->get('topline.started', microtime(true));
        Monitoring::countRequest($response->getStatusCode(), (int) round((microtime(true) - $started) * 1000));
    }
}
