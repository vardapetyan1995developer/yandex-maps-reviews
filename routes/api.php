<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ReviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
| Authentication is session-based (Sanctum SPA), so the auth:sanctum group
| works off a cookie rather than a bearer token.
*/

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('guest')
    ->name('login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
    Route::delete('/organizations/{organization}', [OrganizationController::class, 'destroy']);

    // Re-running collection is its own action rather than part of store, so
    // that the intent is legible from the route
    Route::post('/organizations/{organization}/refresh', [OrganizationController::class, 'refresh']);

    Route::get('/organizations/{organization}/reviews', [ReviewController::class, 'index']);
});
