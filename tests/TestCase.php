<?php

namespace Tests;

use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Services\CaptchaHitungService;
use App\Services\KodeUnitEventService;
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
     * Gabungkan sebuah perangkat ke event, seperti setelah kode unit kerja
     * ditukarkan (FR-EVT-03).
     *
     * Sejak revisi S29, perangkat tidak lagi melayani sebuah event hanya
     * karena unitnya tercakup: keanggotaannya harus dinyatakan lebih dahulu.
     * Pengujian yang menguji apa yang terjadi SETELAH perangkat melayani event
     * memakai jalan pintas ini; yang menguji penggabungannya sendiri menukar
     * kodenya sungguhan lewat `/kiosk/event/gabung`.
     */
    protected function gabungkanKeEvent(EventAbsen $event, Kiosk $kiosk, ?int $unitKerjaId = null): void
    {
        app(KodeUnitEventService::class)->catatKeanggotaan(
            $event,
            $kiosk,
            $unitKerjaId ?? $kiosk->unit_kerja_id,
            '127.0.0.1',
        );
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
