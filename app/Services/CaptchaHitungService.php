<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Tantangan hitungan sederhana untuk layar masuk Panel Admin (FR-AUTH-03).
 *
 * **Cakupannya satu halaman saja: `/masuk`.** Layar tap perangkat absen
 * (`/kiosk/*`) tidak pernah melewatinya — dua alur itu berbeda route, berbeda
 * controller, dan berbeda pagar autentikasi (device token, bukan sesi admin).
 * Karena hal ini mudah rusak tanpa disadari ketika kelak seseorang memasang
 * pemeriksaan CAPTCHA di middleware, batas itu dikunci uji tersendiri; lihat
 * `CaptchaLoginTest::route_kiosk_tidak_pernah_menyentuh_captcha()`.
 *
 * **Mengapa hitungan, bukan gambar terdistorsi.** Gambar yang sengaja dibuat
 * sulit dibaca mesin dengan sendirinya juga sulit dibaca manusia yang memakai
 * pembaca layar — dan itu bertentangan langsung dengan WCAG 2.1 AA yang
 * dipegang proyek ini. Soal hitungan bekerja sama bagi mata dan bagi pembaca
 * layar, karena teksnya memang teks.
 *
 * **Ekspektasi yang jujur.** Ini menaikkan penghalang bagi bot generik yang
 * mencoba kombinasi kata sandi secara massal, dan bekerja berpasangan dengan
 * pembatasan laju bertingkat di {@see AutentikasiService}. Ia TIDAK
 * menghentikan penyerang yang menargetkan sistem ini secara khusus dan menulis
 * pengurainya sendiri — tidak ada CAPTCHA swakelola yang begitu. Untuk ancaman
 * yang realistis di sini, kombinasi keduanya sepadan.
 *
 * **Ringan, dibuat saat diminta.** Hanya dua `random_int` dan satu penulisan
 * sesi: tanpa pustaka gambar, tanpa berkas sementara, tanpa panggilan keluar.
 */
class CaptchaHitungService
{
    public const KUNCI_SESI = 'captcha_jawaban';

    public const KUNCI_SOAL = 'captcha_soal';

    /**
     * Buat soal baru dan simpan jawabannya di sesi.
     *
     * Jawabannya TIDAK pernah dikirim ke klien — tidak sebagai field
     * tersembunyi, tidak sebagai atribut data, tidak sebagai nilai terenkode.
     * Yang menyeberang hanya kalimat soalnya.
     *
     * Angkanya sengaja kecil dan operasinya hanya tambah/kurang: ini penghalang
     * bagi alat isian massal, bukan ujian aritmetika bagi petugas yang sedang
     * terburu-buru pada pukul tujuh pagi.
     */
    public function buat(Request $request): string
    {
        $a = random_int(2, 9);
        $b = random_int(1, 9);

        // Hasil pengurangan dijaga tidak pernah negatif.
        $kurang = $a >= $b && random_int(0, 1) === 1;

        $soal = $kurang ? "{$a} − {$b}" : "{$a} + {$b}";
        $jawaban = $kurang ? $a - $b : $a + $b;

        $request->session()->put(self::KUNCI_SESI, $jawaban);
        $request->session()->put(self::KUNCI_SOAL, $soal);

        return $soal;
    }

    /**
     * Periksa jawaban, lalu HANGUSKAN soalnya apa pun hasilnya.
     *
     * Menghanguskan hanya ketika salah akan membiarkan satu jawaban benar
     * dipakai berulang kali untuk seluruh daftar kata sandi — persis yang
     * hendak dicegah.
     */
    public function benar(Request $request, ?string $jawaban): bool
    {
        $diharapkan = $request->session()->get(self::KUNCI_SESI);

        $this->hapus($request);

        if ($diharapkan === null || $jawaban === null || ! is_numeric(trim($jawaban))) {
            return false;
        }

        return (int) trim($jawaban) === (int) $diharapkan;
    }

    public function hapus(Request $request): void
    {
        $request->session()->forget([self::KUNCI_SESI, self::KUNCI_SOAL]);
    }
}
