<?php

namespace App\Providers;

use App\Models\Kiosk;
use App\Support\PengaturanRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        $this->batasLajuTitikAbsen();
    }

    /**
     * Batas laju endpoint titik absen, dihitung PER PERANGKAT, bukan per IP.
     *
     * Batas per IP tampak wajar sampai seseorang memasang beberapa titik absen
     * di satu kantor: seluruhnya keluar lewat satu NAT, sehingga kuota yang
     * dimaksudkan untuk satu perangkat dibagi rata di antara semuanya. Dengan
     * penarikan Daftar e-Presensi tiap 10 detik (6 permintaan/menit), batas 60
     * per menit habis pada perangkat kesepuluh — dan perangkat kesebelas mulai
     * menerima 429 walau tidak melakukan apa-apa yang berlebihan.
     *
     * Kuncinya karena itu perangkat yang sudah terautentikasi; akun admin pada
     * layar absen umum memakai id akunnya, dan permintaan tanpa keduanya jatuh
     * ke IP seperti semula.
     */
    protected function batasLajuTitikAbsen(): void
    {
        $kunci = fn (Request $request): string => match (true) {
            $request->kiosk() !== null => 'perangkat:'.$request->kiosk()->id,
            $request->user() !== null => 'admin:'.$request->user()->id,
            default => 'ip:'.$request->ip(),
        };

        foreach (['absen-tap' => 120, 'absen-presensi' => 60, 'absen-foto' => 300] as $nama => $batas) {
            RateLimiter::for(
                $nama,
                fn (Request $request) => Limit::perMinute($batas)->by($kunci($request)),
            );
        }
    }
}
