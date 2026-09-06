<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * CAPTCHA hitungan sederhana, sepenuhnya berjalan di server sendiri.
 *
 * **Mengapa bukan layanan pihak ketiga.** Aplikasi ini dipasang di jaringan
 * dinas dan kerap berada di belakang proxy yang menyaring keluar; CAPTCHA yang
 * memanggil layanan luar akan gagal diam-diam di tempat yang justru paling
 * membutuhkannya, dan mengunci admin di luar sistemnya sendiri. Ia juga
 * menuntut kunci API yang harus dirawat orang, serta mengirimkan alamat IP
 * pegawai ke pihak yang tidak punya urusan dengannya.
 *
 * **Mengapa hitungan, bukan gambar.** Gambar berhuruf-miring menuntut
 * `alt` yang menjelaskan isinya bagi pembaca layar — dan begitu `alt`-nya
 * benar, gambar itu berhenti menjadi penghalang. Soal hitungan bekerja sama
 * bagi mata dan bagi pembaca layar, tanpa membuat admin tunanetra terkunci di
 * luar. Ia memang tidak menghentikan penyerang yang menulis pengurai; yang
 * dihentikannya adalah alat isian massal, dan lawan yang sungguh-sungguh
 * ditangani oleh pembatasan laju bertingkat pada {@see AutentikasiService},
 * bukan oleh CAPTCHA.
 *
 * Jawabannya tidak pernah dikirim ke klien. Yang dikirim hanya soalnya;
 * jawabannya tinggal di sesi milik server.
 */
class CaptchaHitungService
{
    public const KUNCI_SESI = 'captcha_jawaban';

    public const KUNCI_SOAL = 'captcha_soal';

    /**
     * Buat soal baru dan simpan jawabannya di sesi.
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
     * Soal yang sedang berlaku, atau soal baru bila belum ada.
     */
    public function soal(Request $request): string
    {
        return $request->session()->get(self::KUNCI_SOAL) ?: $this->buat($request);
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
