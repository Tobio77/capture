<?php

use App\Http\Middleware\AutentikasiKiosk;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HeaderKeamanan;
use App\Http\Middleware\PastikanFiturAbsenAktif;
use App\Http\Middleware\PastikanPenggunaAktif;
use App\Http\Middleware\PastikanPeranPengguna;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\ThrottleRequests;

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

        // Header keamanan pada SETIAP jawaban, termasuk galat dan pengalihan
        // (perbaikan M-2). Lihat App\Http\Middleware\HeaderKeamanan.
        $middleware->append(HeaderKeamanan::class);

        /*
         * Proxy tepercaya (perbaikan M-4).
         *
         * Tanpa ini, aplikasi di belakang nginx, load balancer, atau Cloudflare
         * membaca alamat PROXY sebagai alamat pengunjung — satu nilai yang sama
         * untuk semua orang. Tiga hal ikut rusak sekaligus:
         *
         *   1. Penguncian login per-IP ({@see App\Services\AutentikasiService})
         *      berubah menjadi senjata: 20 percobaan gagal mengunci SELURUH
         *      admin selama 30 menit, sebab semuanya berbagi satu alamat.
         *   2. Audit trail mencatat alamat yang keliru pada aktivasi kiosk,
         *      penggabungan event, dan login — padahal itulah nilai yang dicari
         *      panitia saat menelusuri absen mencurigakan.
         *   3. Batas laju cadangan untuk permintaan tanpa perangkat dan tanpa
         *      admin jatuh ke satu keranjang bersama.
         *
         * Alamatnya disebut lewat env, BUKAN '*': mempercayai proxy mana pun
         * berarti mempercayai header X-Forwarded-For yang dikirim siapa pun,
         * dan penyerang tinggal menuliskan alamat palsu di sana untuk lolos
         * dari penguncian sekaligus mengotori audit trail.
         *
         * Dikosongkan di lingkungan pengembangan yang tidak memakai proxy.
         */
        if (filled($proxy = env('PROXY_TEPERCAYA'))) {
            $middleware->trustProxies(at: array_map('trim', explode(',', (string) $proxy)));
        }

        $middleware->alias([
            'kiosk' => AutentikasiKiosk::class,
            'fitur.absen' => PastikanFiturAbsenAktif::class,
            'peran' => PastikanPeranPengguna::class,
            'pengguna.aktif' => PastikanPenggunaAktif::class,
        ]);

        /*
         * Autentikasi perangkat harus mendahului pembatas laju.
         *
         * Batas laju endpoint titik absen dikunci per perangkat (lihat
         * AppServiceProvider::batasLajuTitikAbsen()), dan perangkatnya baru
         * dikenali setelah AutentikasiKiosk berjalan. Pada urutan bawaan,
         * ThrottleRequests berjalan lebih dahulu, sehingga kuncinya diam-diam
         * jatuh kembali ke alamat IP — dan beberapa perangkat di satu kantor
         * yang berbagi NAT ikut berbagi kuota satu perangkat.
         */
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: AutentikasiKiosk::class,
        );

        $middleware->redirectGuestsTo(fn () => route('masuk'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
