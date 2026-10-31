<?php

use App\Http\Middleware\LoadStore;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequireUser;
use App\Http\Middleware\TrackRequests;
use App\Services\Monitoring;
use App\Support\ApiError;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'topline.user' => RequireUser::class,
            'topline.admin' => RequireAdmin::class,
            'topline.store' => LoadStore::class,
        ]);
        $middleware->api(prepend: [TrackRequests::class]);
        // Behind Hostinger's proxy/CDN: trust X-Forwarded-* so links use https and the real host.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // One error shape for the whole API: {"message": "...", "field": "phone" | null}
        $error = fn (string $message, int $status, ?string $field = null) => response()->json(
            ['message' => $message, 'field' => $field], $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $exceptions->dontReport([ApiError::class]);

        $exceptions->render(fn (ApiError $e) => $error($e->getMessage(), $e->status, $e->field));
        $exceptions->render(fn (AuthenticationException $e, Request $r) => $r->is('api/*') ? $error('Your session has ended. Log in again.', 401) : null);
        $exceptions->render(fn (PostTooLargeException $e, Request $r) => $r->is('api/*')
            ? $error('That photo is too big. Use one under '.config('topline.max_upload_mb').' MB.', 413, 'file') : null);
        $exceptions->render(function (HttpExceptionInterface $e, Request $r) use ($error) {
            if (! $r->is('api/*')) {
                return null;
            }
            $messages = [404 => 'Not found.', 405 => 'Method not allowed.', 429 => 'Too many requests. Wait a moment and try again.'];

            $message = $e->getMessage();
            if ($message === '' || str_starts_with($message, 'The route ') || str_contains($message, 'method is not supported')) {
                $message = $messages[$e->getStatusCode()] ?? 'Request failed.'; // Laravel's wording names internal routes
            }

            return $error($message, $e->getStatusCode());
        });
        // Anything unexpected: log it as a System health problem (with an alert) and answer politely.
        $exceptions->render(function (Throwable $e, Request $r) use ($error) {
            if (! $r->is('api/*')) {
                return null;
            }
            Monitoring::recordIncident('server_error', class_basename($e).': '.mb_substr($e->getMessage(), 0, 150),
                mb_substr($e::class.' in '.$e->getFile().':'.$e->getLine()."\n".$e->getTraceAsString(), 0, 3000), $r->method().' /'.$r->path());

            return $error('Something went wrong on our side. Try again in a moment.', 500);
        });
    })->create();

// The server copy has no composer.json (Hostinger would try to run Composer, which shared
// hosting can't). Laravel only read it to learn the app's namespace, so set it here.
(fn () => $this->namespace = 'App\\')->call($app);

return $app;
