<?php

namespace App\Providers;

use App\Models\Kiosk;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Perangkat kiosk yang sedang terautentikasi, diisi oleh
         * middleware App\Http\Middleware\AutentikasiKiosk.
         */
        Request::macro('kiosk', function (): ?Kiosk {
            /** @var Request $this */
            return $this->attributes->get('kiosk');
        });
    }
}
