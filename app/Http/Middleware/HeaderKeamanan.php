<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan pada setiap jawaban (perbaikan M-2).
 *
 * Sebelum audit pra-deploy, aplikasi ini tidak mengirimkan satu pun header
 * keamanan — satu-satunya yang ada adalah `X-Content-Type-Options` pada proxy
 * foto WORKA. Akibat yang paling nyata: panel admin dapat dibingkai halaman
 * lain, sehingga admin yang sedang login dapat dipancing menekan sakelar Mode
 * Terbuka tanpa pernah melihat layar yang sebenarnya ia sentuh.
 *
 * Yang SENGAJA BELUM ada di sini adalah Content-Security-Policy. CSP yang
 * keliru tidak gagal dengan suara — ia mematikan layar absen di pintu masuk
 * kantor, dan baru ketahuan saat antrean pegawai sudah berdiri. Vite dan
 * Inertia perlu penyetelan tersendiri, jadi CSP dipasang belakangan lewat
 * `Content-Security-Policy-Report-Only` lebih dulu.
 */
class HeaderKeamanan
{
    public function handle(Request $request, Closure $next): Response
    {
        $jawaban = $next($request);

        /*
         * Tidak boleh dibingkai sama sekali. Aplikasi ini tidak punya satu pun
         * layar yang dimaksudkan tampil di dalam iframe situs lain.
         */
        $jawaban->headers->set('X-Frame-Options', 'DENY');

        // Peramban tidak boleh menebak-nebak tipe berkas; foto absen disajikan
        // sebagai aliran biner dan tebakan yang salah dapat mengeksekusinya.
        $jawaban->headers->set('X-Content-Type-Options', 'nosniff');

        // Alamat halaman admin memuat id event dan pegawai; jangan ikut
        // terkirim ke situs luar saat petugas mengeklik tautan.
        $jawaban->headers->set('Referrer-Policy', 'same-origin');

        /*
         * Kamera hanya untuk aplikasi ini sendiri, dan itu pun hanya layar
         * absen. Mikrofon, lokasi, dan pembayaran tidak pernah dibutuhkan —
         * dinyatakan kosong supaya iframe pihak ketiga (yang pun sudah
         * ditolak di atas) tidak dapat memintanya atas nama halaman ini.
         */
        $jawaban->headers->set(
            'Permissions-Policy',
            'camera=(self), microphone=(), geolocation=(), payment=()',
        );

        /*
         * HSTS hanya pada sambungan yang memang sudah aman.
         *
         * Mengirimkannya lewat HTTP tidak ada gunanya — peramban mengabaikan
         * HSTS dari sambungan tak terenkripsi — dan memasangnya di lingkungan
         * pengembangan yang berjalan di HTTP akan mengunci `capture.test` ke
         * HTTPS pada peramban pengembang selama setahun.
         *
         * `preload` sengaja TIDAK disertakan: mendaftarkannya ke daftar bawaan
         * peramban tidak dapat dibatalkan dengan cepat, dan itu keputusan
         * pemilik sistem, bukan bawaan kerangka kerja.
         */
        if ($request->secure()) {
            $jawaban->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $jawaban;
    }
}
