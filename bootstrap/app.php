<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\TenantMiddleware::class,
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'check.feature' => \App\Http\Middleware\CheckFeature::class,
            'verified.email' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);

        // Remove SubstituteBindings from api group so that TenantMiddleware
        // can switch the DB connection BEFORE route model binding resolves models.
        $middleware->api(remove: [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        // Ensure correct ordering: tenant first, then bindings, then auth
        $middleware->priority([
            \App\Http\Middleware\TenantMiddleware::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
