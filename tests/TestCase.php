<?php

namespace Tests;

use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Services\CaptchaHitungService;
use App\Services\EventAbsenService;
use App\Services\SettingAbsenService;
use App\Support\PengaturanRepository;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Matikan sesi absen umum harian.
     *
     * Absen umum menyala secara bawaan, sehingga perangkat yang unitnya tidak
     * sedang menjalankan kegiatan tetap melayani absen rutin. Pengujian yang
     * memeriksa keadaan "tidak ada event sama sekali" — FR-EVT-04 dan
     * penolakan tap di luar cakupan — harus menyatakan prasyarat itu, bukan
     * mengandalkan absen umum kebetulan tidak ada.
     */
    protected function matikanAbsenUmum(): void
    {
        app(PengaturanRepository::class)->simpan(SettingAbsenService::KUNCI_ABSEN_UMUM, '0');
    }

    /**
     * Catat perangkat sebagai pelayan sebuah event, seperti setelah ia membuka
     * layar Absen Event (FR-EVT-03, FR-EVT-05).
     *
     * Sejak S49 keanggotaan tidak lagi menentukan boleh-tidaknya perangkat
     * melayani kegiatan — event berlaku bagi seluruh dinas, sehingga setiap
     * perangkat yang dikenali langsung melayaninya. Yang dicatat baris ini
     * hanyalah jejaknya: perangkat mana, dari unit mana, dari alamat berapa.
     * Pengujian yang memeriksa jejak itu memakai jalan pintas ini alih-alih
     * membuka layarnya lebih dahulu.
     */
    protected function gabungkanKeEvent(EventAbsen $event, Kiosk $kiosk): void
    {
        app(EventAbsenService::class)->catatKioskAktif($event, $kiosk, '127.0.0.1');
    }

    /**
     * Kirim formulir masuk admin lengkap dengan jawaban hitungannya.
     *
     * CAPTCHA diminta sejak percobaan pertama (FR-AUTH-03), sehingga setiap
     * uji yang menyentuh `/masuk` harus lebih dulu MEMBUKA layarnya untuk
     * memperoleh soal — jawabannya tidak pernah dikirim ke klien, ia tinggal
     * di sesi milik server.
     *
     * Disediakan sekali di sini alih-alih disalin ke tiap berkas uji: langkah
     * "buka layar dulu" adalah bagian dari alur yang sebenarnya, dan uji yang
     * melewatinya akan menguji keadaan yang tidak pernah dialami siapa pun.
     *
     * @param  array<string, mixed>  $kredensial
     */
    protected function kirimMasuk(array $kredensial): TestResponse
    {
        $this->get('/masuk');

        return $this->post('/masuk', [
            ...$kredensial,
            'jawaban_captcha' => (string) session(CaptchaHitungService::KUNCI_SESI),
        ]);
    }
}
