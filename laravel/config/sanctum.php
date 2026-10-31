<?php

/*
| Login tokens. The website sends "Authorization: Bearer <token>"; no cookies are used.
| Tokens expire after SESSION_HOURS (default 72); changing a password deletes them all.
*/
return [
    'stateful' => [],
    'guard' => [],
    'expiration' => null, // each token carries its own expires_at
    'expiration_hours' => (int) env('SESSION_HOURS', 72),
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
