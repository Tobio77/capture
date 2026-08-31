<?php

namespace App\Providers;

use App\Models\Kiosk;
use App\Support\PengaturanRepository;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Satu instans per permintaan: pengaturan dibaca berulang kali
         * (klien WORKA, halaman setting, status sinkronisasi) dan
         * ingatannya hanya berguna bila instansnya dibagi.
         */
        $this->app->singleton(PengaturanRepository::class);
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
