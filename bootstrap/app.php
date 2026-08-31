<?php

use App\Http\Middleware\AutentikasiKiosk;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PastikanPenggunaAktif;
use App\Http\Middleware\PastikanPeranPengguna;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'kiosk' => AutentikasiKiosk::class,
            'peran' => PastikanPeranPengguna::class,
            'pengguna.aktif' => PastikanPenggunaAktif::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('masuk'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
