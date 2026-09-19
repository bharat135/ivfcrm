<?php

use App\Http\Middleware\SingleDeviceLogin;
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
            'single.device' => SingleDeviceLogin::class,
        ]);

        // Presence for lead assignment: only agents seen recently get leads.
        $middleware->web(append: [\App\Http\Middleware\TrackLastSeen::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
