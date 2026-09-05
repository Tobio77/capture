<?php

namespace Tests;

use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Services\KodeUnitEventService;
use App\Services\SettingAbsenService;
use App\Support\PengaturanRepository;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
}
