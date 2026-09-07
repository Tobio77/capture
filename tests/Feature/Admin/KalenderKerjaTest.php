<?php

namespace Tests\Feature\Admin;

use App\Enums\JenisAbsen;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\HariLibur;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\AbsensiService;
use App\Services\AbsenUmumService;
use App\Services\KalenderKerjaService;
use App\Services\KioskService;
use App\Services\PerhatianDashboardService;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kalender hari kerja dan hari libur (FR-SET-08).
 *
 * Keputusan pokoknya: hari libur MENANDAI, tidak menutup. Kantor dinas
 * menjalankan piket akhir pekan, dan menutup absen umum berarti petugas piket
 * yang benar-benar masuk tidak dapat mencatat kehadirannya sama sekali.
 *
 * Yang paling mudah rusak diam-diam — dan karena itu diuji terpisah — bukan
 * "hari libur terdeteksi", melainkan tiga hal ini:
 *
 *   1. Tap pada hari libur TETAP DITERIMA. Begitu seseorang kelak menyamakan
 *      "libur" dengan "tertutup", petugas piket terkunci di luar sistem.
 *   2. Penandanya DISIMPAN, bukan dihitung ulang saat dibaca. Kalender dapat
 *      berubah kemudian, dan catatan administratif tidak boleh ikut berubah.
 *   3. Hari kerja diwarisi dari induk. Menuntut setiap seksi mengaturnya
 *      sendiri hanya melahirkan puluhan salinan yang akan berbeda diam-diam.
 */
class KalenderKerjaTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-perangkat-kalender';

    protected const NIP = '199001012020011001';

    protected UnitKerja $opd;

    protected UnitKerja $upt;

    protected UnitKerja $seksi;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AbsensiService::DISK);

        // Sabtu, 5 September 2026, pukul 07.35 — di dalam jendela datang.
        $this->travelTo('2026-09-05 07:35:00');

        $this->opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $this->upt = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $this->opd->id]);
        $this->seksi = UnitKerja::factory()->create(['kode' => 'BLK-SBY-TU', 'induk_id' => $this->upt->id]);

        Kiosk::factory()->diaktifkan(self::TOKEN)->create(['unit_kerja_id' => $this->upt->id]);
        Pegawai::factory()->create(['nip' => self::NIP, 'unit_kerja_id' => $this->upt->id]);

        $this->admin = User::factory()->superadmin()->create();

        app(SettingAbsenService::class)->simpan(['metode_wajah_aktif' => false], $this->admin);
    }

    protected function kalender(): KalenderKerjaService
    {
        return app(KalenderKerjaService::class);
    }

    protected function tap()
    {
        return $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->post('/kiosk/umum/absen', [
                'id_card' => self::NIP,
                'jenis' => 'datang',
                'metode' => 'manual',
            ], ['Accept' => 'application/json']);
    }

    /* ---------------------------------------------------------------------
     * Menandai, bukan menutup
     * ------------------------------------------------------------------- */

    #[Test]
    public function tap_pada_hari_libur_tetap_diterima(): void
    {
        /*
         * Syarat yang paling penting di seluruh berkas ini. Petugas piket yang
         * masuk Sabtu harus tetap dapat mencatat kehadirannya tanpa meminta
         * admin membuka paksa dari rumah.
         */
        $this->tap()->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, Absensi::query()->count());
    }

    #[Test]
    public function tap_pada_hari_libur_ditandai(): void
    {
        $this->tap();

        $this->assertTrue(
            Absensi::query()->sole()->hari_libur,
            'Tap di luar hari kerja harus tercatat dengan penandanya.',
        );
    }

    #[Test]
    public function tap_pada_hari_kerja_tidak_ditandai(): void
    {
        // Senin, 7 September 2026.
        $this->travelTo('2026-09-07 07:35:00');

        $this->tap();

        $this->assertFalse(Absensi::query()->sole()->hari_libur);
    }

    #[Test]
    public function penanda_tidak_berubah_ketika_kalender_disunting_kemudian(): void
    {
        /*
         * Absensi adalah catatan administratif. Yang berubah arti setiap kali
         * admin menambahkan tanggal merah yang terlewat tidak dapat
         * dipertanggungjawabkan — laporan bulan lalu akan berbeda isinya
         * setiap kali dibuka.
         */
        $this->travelTo('2026-09-07 07:35:00');
        $this->tap();

        $this->assertFalse(Absensi::query()->sole()->hari_libur);

        HariLibur::query()->create([
            'tanggal' => '2026-09-07',
            'keterangan' => 'Cuti bersama yang baru diumumkan',
        ]);

        $this->assertFalse(
            Absensi::query()->sole()->fresh()->hari_libur,
            'Penanda yang sudah tersimpan tidak boleh ikut berubah.',
        );
    }

    /* ---------------------------------------------------------------------
     * Hari kerja per unit, diwarisi dari induk
     * ------------------------------------------------------------------- */

    #[Test]
    public function bawaannya_senin_sampai_jumat(): void
    {
        $this->assertSame(
            KalenderKerjaService::HARI_KERJA_BAWAAN,
            $this->kalender()->hariKerjaUnit($this->upt->id),
        );

        $this->assertFalse($this->kalender()->hariKerja($this->upt->id));
    }

    #[Test]
    public function unit_yang_bekerja_sabtu_tidak_dianggap_libur(): void
    {
        /*
         * Inti keputusan "per unit, bukan global": UPT BLK menyelenggarakan
         * pelatihan yang kerap berjalan Sabtu, sementara kantor dinas tidak.
         * Aturan tunggal akan salah bagi salah satunya.
         */
        $this->upt->update(['hari_kerja' => [1, 2, 3, 4, 5, 6]]);

        $this->assertTrue($this->kalender()->hariKerja($this->upt->id));
        $this->assertFalse($this->kalender()->hariKerja($this->opd->id));
    }

    #[Test]
    public function seksi_mewarisi_hari_kerja_induknya(): void
    {
        $this->upt->update(['hari_kerja' => [1, 2, 3, 4, 5, 6]]);

        $this->assertTrue(
            $this->kalender()->hariKerja($this->seksi->id),
            'Seksi tanpa pengaturan sendiri harus mengikuti UPT yang menaunginya.',
        );
    }

    #[Test]
    public function pengaturan_unit_sendiri_mengalahkan_induknya(): void
    {
        $this->upt->update(['hari_kerja' => [1, 2, 3, 4, 5, 6]]);
        $this->seksi->update(['hari_kerja' => [1, 2, 3, 4, 5]]);

        $this->assertFalse($this->kalender()->hariKerja($this->seksi->id));
    }

    /* ---------------------------------------------------------------------
     * Hari libur bertanggal
     * ------------------------------------------------------------------- */

    #[Test]
    public function libur_nasional_berlaku_bagi_semua_unit(): void
    {
        HariLibur::query()->create([
            'tanggal' => '2026-09-07',
            'keterangan' => 'Hari Raya Idulfitri',
        ]);

        $tanggal = Carbon::parse('2026-09-07');

        $this->assertSame('Hari Raya Idulfitri', $this->kalender()->alasanLibur($this->upt->id, $tanggal));
        $this->assertSame('Hari Raya Idulfitri', $this->kalender()->alasanLibur($this->opd->id, $tanggal));
    }

    #[Test]
    public function libur_khusus_unit_tidak_menyentuh_unit_lain(): void
    {
        $lain = UnitKerja::factory()->create(['kode' => 'BLK-MLG', 'induk_id' => $this->opd->id]);

        HariLibur::query()->create([
            'tanggal' => '2026-09-07',
            'keterangan' => 'HUT UPT BLK Surabaya',
            'unit_kerja_id' => $this->upt->id,
        ]);

        $tanggal = Carbon::parse('2026-09-07');

        $this->assertSame('HUT UPT BLK Surabaya', $this->kalender()->alasanLibur($this->upt->id, $tanggal));
        $this->assertNull($this->kalender()->alasanLibur($lain->id, $tanggal));
    }

    #[Test]
    public function libur_bertanggal_mengalahkan_hari_kerja(): void
    {
        /*
         * Tanggal merah yang jatuh pada hari Rabu harus menyebut NAMANYA,
         * bukan didiamkan karena Rabu kebetulan hari kerja.
         */
        HariLibur::query()->create([
            'tanggal' => '2026-09-09',
            'keterangan' => 'Cuti Bersama',
        ]);

        $this->assertSame(
            'Cuti Bersama',
            $this->kalender()->alasanLibur($this->upt->id, Carbon::parse('2026-09-09')),
        );
    }

    #[Test]
    public function alasan_akhir_pekan_menyebut_nama_harinya(): void
    {
        $alasan = $this->kalender()->alasanLibur($this->upt->id, Carbon::parse('2026-09-05'));

        $this->assertNotNull($alasan);
        $this->assertStringContainsString('Sabtu', $alasan);
    }

    /* ---------------------------------------------------------------------
     * Dampak ke layar dan sinyal
     * ------------------------------------------------------------------- */

    #[Test]
    public function status_absen_umum_menyebut_hari_libur_tanpa_menutupnya(): void
    {
        $this->tap();

        $status = app(AbsenUmumService::class)
            ->status(JenisAbsen::Datang, app(AbsenUmumService::class)->sesi($this->upt->id));

        $this->assertTrue($status->terbuka, 'Hari libur menandai, bukan menutup.');
        $this->assertStringContainsString('Sabtu', $status->keterangan());
        $this->assertStringContainsString('ditandai hari libur', $status->keterangan());
    }

    #[Test]
    public function sinyal_kehadiran_anjlok_diam_pada_hari_libur(): void
    {
        /*
         * Tanpa penjagaan ini, setiap Senin pagi panel Perlu Perhatian penuh
         * laporan bahwa kehadiran Sabtu dan Minggu "anjlok" — benar secara
         * hitungan, dan tidak menuntut tindakan apa pun.
         */
        $this->travelTo('2026-09-05 10:00:00');

        $pegawai = Pegawai::factory()->count(10)->create(['unit_kerja_id' => $this->upt->id]);

        foreach (['2026-09-02', '2026-09-03', '2026-09-04'] as $tanggal) {
            foreach ($pegawai->take(6) as $orang) {
                Absensi::query()->create([
                    'event_absen_id' => EventAbsen::factory()->umum()->create([
                        'tanggal' => $tanggal,
                    ])->id,
                    'pegawai_id' => $orang->id,
                    'jenis' => JenisAbsen::Datang,
                    'metode' => 'manual',
                    'waktu' => "{$tanggal} 07:31:00",
                ]);
            }
        }

        $butir = array_filter(
            app(PerhatianDashboardService::class)->untuk($this->admin),
            fn ($b) => $b['jenis'] === 'kehadiran_anjlok',
        );

        $this->assertSame([], array_values($butir));
    }
}
