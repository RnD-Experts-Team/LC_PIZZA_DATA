<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.token.store' => \App\Http\Middleware\AuthTokenStoreScopeMiddleware::class,
            'auth.secret.key' => \App\Http\Middleware\CheckSecretKeyMiddleware::class,
            'throttle' => \App\Http\Middleware\ObservableThrottleRequests::class,
        ]);

        // The proxies themselves come from config/trustedproxy.php (TRUSTED_PROXIES).
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
