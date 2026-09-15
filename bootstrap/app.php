<?php

declare(strict_types=1);

use App\Exceptions\Scraping\ScrapingException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust the platform's load balancer.
        //
        // Render (like most managed hosts) terminates TLS at the edge and
        // forwards the request over plain HTTP, marking the original scheme in
        // X-Forwarded-Proto. Laravel ignores that header unless the proxy is
        // trusted, so it considers the request insecure and generates http://
        // asset URLs on an https:// page. The browser then blocks them as mixed
        // content and the SPA never boots — a blank page with a 200 status.
        //
        // The wildcard is appropriate here because the container is reachable
        // only through that proxy; it is not exposed directly.
        $middleware->trustProxies(at: '*');

        // Sanctum in SPA mode: requests from the trusted frontend authenticate
        // with a session cookie rather than a header token. An HttpOnly cookie
        // cannot be read by injected script, unlike a token in localStorage.
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        // The SPA is served as a single HTML file, so every non-API route must
        // reach the frontend router instead of 404ing
        $middleware->validateCsrfTokens(except: []);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Scraping errors become a meaningful API response rather than a 500:
        // the client should receive a cause it can act on
        $exceptions->render(function (ScrapingException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $e->userMessage(),
                'error' => [
                    'code' => $e->reason()->value,
                    'detail' => $e->getMessage(),
                ],
            ], 422);
        });
    })->create();
