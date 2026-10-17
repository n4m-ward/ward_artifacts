<?php

use App\Http\Middleware\RestrictContentHost;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')->group(base_path('routes/uploads.php'));
            Route::group([], base_path('routes/content.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(RestrictContentHost::class);

        // Na Hostinger o app fica atrás da CDN: sem isso todo mundo teria o IP
        // da CDN e os limites de taxa (login, upload, OAuth) seriam compartilhados.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
