<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
| The backend serves a single HTML document; vue-router handles all navigation
| inside it. The exceptions are Laravel's and Sanctum's own prefixes, which the
| frontend must not intercept.
*/

Route::view('/{any?}', 'app')
    ->where('any', '^(?!api|sanctum|storage|up|build).*$')
    ->name('spa');
