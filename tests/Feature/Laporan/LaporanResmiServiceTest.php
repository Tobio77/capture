<?php

namespace Tests\Feature\Laporan;

use App\Enums\JenisAbsen;
use App\Enums\StatusKetepatan;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\Laporan\LaporanResmiService;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Susunan data Laporan Resmi (FR-LAP-04).
 *
 * Periode uji tetap Senin 7 — Jumat 11 September 2026: lima hari kerja
 * berturut-turut TANPA akhir pekan di dalamnya, supaya jumlah hari kerja
 * kalender dapat dihitung tangan tanpa ambigu (5 hari × jumlah pegawai).
 */
class LaporanResmiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected const DARI = '2026-09-07';

    protected const SAMPAI = '2026-09-11';

    protected UnitKerja $opd;

    protected UnitKerja $upt;

    protected User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS', 'nama' => 'Dinas Tenaga Kerja']);
        $this->upt = UnitKerja::factory()->create([
            'kode' => 'BLK-SBY',
            'nama' => 'UPT Balai Latihan Kerja di Surabaya',
            'induk_id' => $this->opd->id,
        ]);
        $this->superadmin = User::factory()->superadmin()->create();
    }

    protected function layanan(): LaporanResmiService
    {
        return app(LaporanResmiService::class);
    }

    protected function susun(?int $unitKerjaId = null): array
    {
        return $this->layanan()->susun(
            $this->superadmin,
            Carbon::parse(self::DARI),
            Carbon::parse(self::SAMPAI),
            $unitKerjaId,
        );
    }

    protected function eventKegiatan(string $tanggal, UnitKerja $unit): EventAbsen
    {
        $event = EventAbsen::factory()->create(['tanggal' => $tanggal]);
        $event->unitKerja()->attach($unit);

        return $event;
    }

    protected function tap(EventAbsen $event, Pegawai $pegawai, string $jamTanggal, bool $terlambat = false): Absensi
    {
        return Absensi::query()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => $pegawai->id,
            'jenis' => JenisAbsen::Datang,
            'metode' => 'manual',
            'waktu' => $jamTanggal,
            'status_ketepatan' => $terlambat ? StatusKetepatan::Terlambat : StatusKetepatan::Tepat,
        ]);
    }

    /* ---------------------------------------------------------------------
     * Penyebut: kegiatan dan harian dihitung terpisah, tidak dobel.
     * ------------------------------------------------------------------- */

    #[Test]
    public function harian_dihitung_dari_kalender_bukan_dari_sesi_yang_kebetulan_ada(): void
    {
        // Dua pegawai, tanpa satu pun kegiatan, tanpa satu pun tap — hanya
        // hari kerja kalender yang menentukan ekspektasi.
        Pegawai::factory()->count(2)->create(['unit_kerja_id' => $this->upt->id]);

        $unit = $this->susun()['per_unit'][0];

        $this->assertSame(0, $unit['hadir']);
        $this->assertSame(10, $unit['total_ekspektasi'], '5 hari kerja × 2 pegawai = 10.');
        $this->assertSame(0.0, $unit['tingkat_kehadiran']);
    }

    #[Test]
    public function sesi_absen_umum_yang_sudah_ada_tidak_menggandakan_ekspektasi(): void
    {
        /*
         * Penjaga UTAMA berkas ini. Sebelum diperbaiki, ekspektasi dihitung
         * dari `event_berlaku` LaporanService (yang sudah menghitung sesi
         * Absen Umum yang ada) DITAMBAH hari kalender — hari yang kebetulan
         * py sesi akan terhitung dua kali.
         */
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $this->upt->id]);

        // Satu sesi Absen Umum lahir pada 8 September karena pegawai ini tap.
        $sesi = EventAbsen::factory()->umum()->create(['tanggal' => '2026-09-08']);
        $sesi->unitKerja()->attach($this->upt);
        $this->tap($sesi, $pegawai, '2026-09-08 07:31:00');

        $unit = $this->susun()['per_unit'][0];

        // TETAP 5 (bukan 6): hari itu sudah terhitung sebagai hari kerja
        // kalender, sesi yang ada tidak menambah apa pun di atasnya.
        $this->assertSame(5, $unit['total_ekspektasi']);
        $this->assertSame(1, $unit['hadir']);
        $this->assertSame(20.0, $unit['tingkat_kehadiran']);
    }

    #[Test]
    public function kegiatan_dan_harian_digabung_pada_unit_yang_sama(): void
    {
        $pegawai = Pegawai::factory()->count(2)->create(['unit_kerja_id' => $this->upt->id]);

        $this->eventKegiatan('2026-09-09', $this->upt);

        $unit = $this->susun()['per_unit'][0];

        // (5 hari kerja + 1 kegiatan) × 2 pegawai = 12.
        $this->assertSame(12, $unit['total_ekspektasi']);
    }

    #[Test]
    public function absen_umum_mati_tidak_menyumbang_ekspektasi_harian(): void
    {
        Pegawai::factory()->create(['unit_kerja_id' => $this->upt->id]);
        $this->eventKegiatan('2026-09-09', $this->upt);

        app(SettingAbsenService::class)->simpan(['absen_umum_aktif' => false], $this->superadmin);

        $unit = $this->susun()['per_unit'][0];

        // Hanya kegiatan yang tersisa: 1 event × 1 pegawai.
        $this->assertSame(1, $unit['total_ekspektasi']);
    }

    #[Test]
    public function kegiatan_pada_seksi_tetap_terhitung_pada_unit_teratasnya(): void
    {
        $seksi = UnitKerja::factory()->create(['kode' => 'BLK-SBY-TU', 'induk_id' => $this->upt->id]);
        Pegawai::factory()->create(['unit_kerja_id' => $seksi->id]);

        // Event dicakupkan langsung ke SEKSI, bukan ke UPT induknya.
        $this->eventKegiatan('2026-09-09', $seksi);

        $unit = $this->susun()['per_unit'][0];

        $this->assertSame($this->upt->id, $unit['unit_kerja_id'], 'Dikelompokkan ke UPT, bukan seksinya.');
        $this->assertSame(6, $unit['total_ekspektasi'], '5 hari kerja + 1 kegiatan.');
    }

    #[Test]
    public function unit_tanpa_kegiatan_maupun_hari_kerja_dikecualikan_bukan_nol_persen(): void
    {
        Pegawai::factory()->create(['unit_kerja_id' => $this->upt->id]);
        app(SettingAbsenService::class)->simpan(['absen_umum_aktif' => false], $this->superadmin);

        $unit = $this->susun()['per_unit'][0];

        $this->assertSame(0, $unit['total_ekspektasi']);
        $this->assertNull($unit['tingkat_kehadiran'], 'Tidak punya kewajiban apa pun, bukan gagal hadir.');
    }

    #[Test]
    public function filter_unit_membatasi_cakupan_rincian(): void
    {
        $lain = UnitKerja::factory()->create(['kode' => 'BLK-MLG', 'induk_id' => $this->opd->id]);
        Pegawai::factory()->create(['unit_kerja_id' => $this->upt->id]);
        Pegawai::factory()->create(['unit_kerja_id' => $lain->id]);

        $hasil = $this->susun($this->upt->id);

        $this->assertCount(1, $hasil['per_unit']);
        $this->assertSame('UPT Balai Latihan Kerja di Surabaya', $hasil['cakupan']);
    }

    /* ---------------------------------------------------------------------
     * Periode pembanding.
     * ------------------------------------------------------------------- */

    #[Test]
    public function periode_bulan_penuh_dibandingkan_bulan_kalender_sebelumnya(): void
    {
        $hasil = $this->layanan()->susun(
            $this->superadmin,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30'),
            null,
        );

        $this->assertSame('1–31 Agustus 2026', $hasil['periode_pembanding_label']);
    }

    #[Test]
    public function periode_custom_dibandingkan_rentang_berpanjang_sama_sebelumnya(): void
    {
        // 7—11 September (5 hari) → pembanding 5 hari tepat sebelumnya.
        $hasil = $this->susun();

        $this->assertSame('2–6 September 2026', $hasil['periode_pembanding_label']);
    }

    /* ---------------------------------------------------------------------
     * Kesimpulan — tiga varian.
     * ------------------------------------------------------------------- */

    #[Test]
    public function kesimpulan_menyebut_kenaikan_dibanding_periode_sebelumnya(): void
    {
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $this->upt->id]);

        // Periode sekarang: hadir semua 5 hari. Periode pembanding (2—6
        // September, juga 5 hari kerja): tidak ada tap sama sekali.
        foreach (['07', '08', '09', '10', '11'] as $tgl) {
            $sesi = EventAbsen::factory()->umum()->create(['tanggal' => "2026-09-{$tgl}"]);
            $sesi->unitKerja()->attach($this->upt);
            $this->tap($sesi, $pegawai, "2026-09-{$tgl} 07:31:00");
        }

        $hasil = $this->susun();

        $this->assertStringContainsString('mencapai 100,0%', $hasil['kesimpulan']);
        $this->assertStringContainsString('naik', $hasil['kesimpulan']);
        $this->assertStringContainsString('2–6 September 2026', $hasil['kesimpulan']);
    }

    #[Test]
    public function kesimpulan_menyebut_tidak_ada_data_sama_sekali(): void
    {
        app(SettingAbsenService::class)->simpan(['absen_umum_aktif' => false], $this->superadmin);

        // Tidak ada pegawai, tidak ada kegiatan — total_ekspektasi nol total.
        $hasil = $this->susun();

        $this->assertStringContainsString('Tidak terdapat kegiatan maupun hari kerja', $hasil['kesimpulan']);
        $this->assertStringContainsString('belum dapat dihitung', $hasil['kesimpulan']);
    }

    /* ---------------------------------------------------------------------
     * Rekomendasi — dua kelompok terpisah.
     * ------------------------------------------------------------------- */

    #[Test]
    public function unit_di_bawah_ambang_kehadiran_direkomendasikan(): void
    {
        // Ambang bawaan 80%. Satu pegawai, tanpa satu pun tap → 0%.
        Pegawai::factory()->create(['unit_kerja_id' => $this->upt->id]);

        $hasil = $this->susun();

        $this->assertCount(1, $hasil['rekomendasi']['kehadiran_rendah']);
        $this->assertStringContainsString(
            'UPT Balai Latihan Kerja di Surabaya',
            $hasil['rekomendasi']['kehadiran_rendah'][0]['kalimat'],
        );
        $this->assertStringContainsString('80%', $hasil['rekomendasi']['kehadiran_rendah'][0]['kalimat']);
        $this->assertCount(0, $hasil['rekomendasi']['keterlambatan_tinggi']);
        $this->assertFalse($hasil['rekomendasi']['tidak_ada_masalah']);
    }

    #[Test]
    public function unit_di_atas_ambang_keterlambatan_direkomendasikan_terpisah(): void
    {
        // Ambang bawaan 15%. Satu pegawai, hadir penuh tapi selalu terlambat.
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $this->upt->id]);

        foreach (['07', '08', '09', '10', '11'] as $tgl) {
            $sesi = EventAbsen::factory()->umum()->create(['tanggal' => "2026-09-{$tgl}"]);
            $sesi->unitKerja()->attach($this->upt);
            $this->tap($sesi, $pegawai, "2026-09-{$tgl} 09:00:00", terlambat: true);
        }

        $hasil = $this->susun();

        $this->assertCount(1, $hasil['rekomendasi']['keterlambatan_tinggi']);
        $this->assertStringContainsString('100,0%', $hasil['rekomendasi']['keterlambatan_tinggi'][0]['kalimat']);
        $this->assertStringContainsString('15%', $hasil['rekomendasi']['keterlambatan_tinggi'][0]['kalimat']);

        // Kehadirannya sendiri 100% (5/5) — tidak kena ambang kehadiran.
        $this->assertCount(0, $hasil['rekomendasi']['kehadiran_rendah']);
    }

    #[Test]
    public function unit_yang_kena_kedua_ambang_muncul_di_kedua_kelompok(): void
    {
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $this->upt->id]);

        // Hadir hanya 1 dari 5 hari kerja (20%, di bawah ambang 80%), dan
        // satu-satunya kehadiran itu terlambat (100%, di atas ambang 15%).
        $sesi = EventAbsen::factory()->umum()->create(['tanggal' => '2026-09-08']);
        $sesi->unitKerja()->attach($this->upt);
        $this->tap($sesi, $pegawai, '2026-09-08 09:00:00', terlambat: true);

        $hasil = $this->susun();

        $this->assertCount(1, $hasil['rekomendasi']['kehadiran_rendah']);
        $this->assertCount(1, $hasil['rekomendasi']['keterlambatan_tinggi']);
        $this->assertFalse($hasil['rekomendasi']['tidak_ada_masalah']);
    }

    #[Test]
    public function tidak_ada_unit_yang_kena_ambang_menghasilkan_kalimat_positif(): void
    {
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $this->upt->id]);

        foreach (['07', '08', '09', '10', '11'] as $tgl) {
            $sesi = EventAbsen::factory()->umum()->create(['tanggal' => "2026-09-{$tgl}"]);
            $sesi->unitKerja()->attach($this->upt);
            $this->tap($sesi, $pegawai, "2026-09-{$tgl} 07:31:00");
        }

        $hasil = $this->susun();

        $this->assertTrue($hasil['rekomendasi']['tidak_ada_masalah']);
        $this->assertCount(0, $hasil['rekomendasi']['kehadiran_rendah']);
        $this->assertCount(0, $hasil['rekomendasi']['keterlambatan_tinggi']);
    }

    #[Test]
    public function ambang_yang_diubah_admin_ikut_dipakai(): void
    {
        Pegawai::factory()->create(['unit_kerja_id' => $this->upt->id]);

        // Dengan ambang bawaan 80%, unit tanpa tap sama sekali (0%) kena
        // rekomendasi. Turunkan ke 0% — tidak ada lagi yang boleh kena.
        app(SettingAbsenService::class)->simpan(['ambang_kehadiran_minimum' => 0], $this->superadmin);

        $hasil = $this->susun();

        $this->assertCount(0, $hasil['rekomendasi']['kehadiran_rendah']);
    }
}
