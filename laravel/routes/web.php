<?php

use Illuminate\Support\Facades\Route;

/*
| The website (React) is built into public/ (index.html + assets/). Every page address that isn't
| a real file and isn't /api/... gets index.html, so links like /admin or /s/mjengo work when
| opened directly or refreshed.
*/
Route::get('/{any?}', function () {
    $index = public_path('index.html');
    abort_unless(is_file($index), 404, 'Website not built yet: run the build script (see SETUP-HOSTINGER.md).');

    return response()->file($index, ['Cache-Control' => 'no-cache']);
})->where('any', '^(?!api/).*$');
