<?php

namespace Tests;

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
}
