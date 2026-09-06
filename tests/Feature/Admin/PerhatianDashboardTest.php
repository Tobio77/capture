<?php

namespace Tests\Feature\Admin;

use App\Enums\JenisAbsen;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\PerhatianDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Panel "Perlu Perhatian" pada Dashboard (FR-DASH-04).
 *
 * Yang dijaga di sini bukan bahwa sinyalnya MUNCUL, melainkan bahwa ia
 * DIAM ketika tidak ada apa-apa. Peringatan yang menyala terus adalah
 * peringatan yang berhenti dibaca pada minggu kedua, dan sesudah itu ia
 * tidak lagi menyelamatkan siapa pun ketika benar-benar menyala.
 */
class PerhatianDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected UnitKerja $upt;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Setelah batas tepat waktu (07.30 + 15 menit) supaya sinyal
        // kehadiran ikut dihitung; sebelum itu ia sengaja tidak berlaku.
        $this->travelTo('2026-09-07 10:00:00');

        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $this->upt = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $opd->id]);
        $this->admin = User::factory()->superadmin()->create();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function perhatian(?User $pelaku = null): array
    {
        return app(PerhatianDashboardService::class)->untuk($pelaku ?? $this->admin);
    }

    /**
     * @param  array<int, array<string, mixed>>  $daftar
     * @return array<int, array<string, mixed>>
     */
    protected function jenis(array $daftar, string $jenis): array
    {
        return array_values(array_filter($daftar, fn ($b) => $b['jenis'] === $jenis));
    }

    #[Test]
    public function tanpa_masalah_panelnya_kosong(): void
    {
        $this->assertSame([], $this->perhatian());

        $this->actingAs($this->admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $halaman) => $halaman->has('perhatian', 0)->etc());
    }

    /* ---------------------------------------------------------------------
     * Kegiatan yang lupa ditutup
     * ------------------------------------------------------------------- */

    #[Test]
    public function kegiatan_kemarin_yang_masih_dibuka_dilaporkan(): void
    {
        EventAbsen::factory()->create([
            'nama' => 'Apel Pagi Jumat',
            'tanggal' => '2026-09-04',
        ])->unitKerja()->attach($this->upt);

        $butir = $this->jenis($this->perhatian(), 'event_terbuka');

        $this->assertCount(1, $butir);
        $this->assertSame('Apel Pagi Jumat', $butir[0]['judul']);
    }

    #[Test]
    public function kegiatan_hari_ini_yang_dibuka_bukan_masalah(): void
    {
        // Justru inilah keadaan normalnya sepanjang hari kegiatan berlangsung.
        EventAbsen::factory()->create(['tanggal' => '2026-09-07'])
            ->unitKerja()->attach($this->upt);

        $this->assertSame([], $this->jenis($this->perhatian(), 'event_terbuka'));
    }

    #[Test]
    public function sesi_absen_umum_kemarin_bukan_kegiatan_yang_lupa_ditutup(): void
    {
        /*
         * Sesi harian memang dibuka sistem dan tidak pernah ditutup tangan
         * manusia. Menghitungnya sebagai kelalaian akan membuat panel ini
         * menyala setiap hari sejak hari kedua — dan berhenti dibaca.
         */
        EventAbsen::factory()->umum()->create(['tanggal' => '2026-09-04'])
            ->unitKerja()->attach($this->upt);

        $this->assertSame([], $this->jenis($this->perhatian(), 'event_terbuka'));
    }

    /* ---------------------------------------------------------------------
     * Perangkat yang sunyi
     * ------------------------------------------------------------------- */

    #[Test]
    public function perangkat_aktif_tanpa_tap_sama_sekali_dilaporkan(): void
    {
        Kiosk::factory()->diaktifkan('token-sunyi')->create([
            'nama_titik' => 'Aula Lantai 3',
            'unit_kerja_id' => $this->upt->id,
            'diaktifkan_pada' => '2026-08-01 08:00:00',
        ]);

        $butir = $this->jenis($this->perhatian(), 'perangkat_sunyi');

        $this->assertCount(1, $butir);
        $this->assertSame('Aula Lantai 3', $butir[0]['judul']);
    }

    #[Test]
    public function perangkat_yang_baru_melayani_tap_tidak_dilaporkan(): void
    {
        $kiosk = Kiosk::factory()->diaktifkan('token-sibuk')->create([
            'unit_kerja_id' => $this->upt->id,
        ]);

        $this->tap($kiosk, '2026-09-07 07:31:00');

        $this->assertSame([], $this->jenis($this->perhatian(), 'perangkat_sunyi'));
    }

    #[Test]
    public function perangkat_yang_sunyi_dua_hari_belum_dilaporkan(): void
    {
        /*
         * Akhir pekan membuat setiap perangkat sunyi dua hari berturut-turut
         * secara wajar. Ambangnya karena itu tiga hari, bukan satu.
         */
        $kiosk = Kiosk::factory()->diaktifkan('token-akhir-pekan')->create([
            'unit_kerja_id' => $this->upt->id,
        ]);

        $this->tap($kiosk, '2026-09-05 07:31:00');

        $this->assertSame([], $this->jenis($this->perhatian(), 'perangkat_sunyi'));
    }

    /* ---------------------------------------------------------------------
     * Kehadiran yang anjlok
     * ------------------------------------------------------------------- */

    #[Test]
    public function unit_yang_kehadirannya_anjlok_dilaporkan(): void
    {
        $pegawai = Pegawai::factory()->count(10)->create(['unit_kerja_id' => $this->upt->id]);

        // Enam hadir tiap hari selama tiga hari sebelumnya…
        foreach (['2026-09-04', '2026-09-05', '2026-09-06'] as $tanggal) {
            foreach ($pegawai->take(6) as $orang) {
                $this->tapPegawai($orang, "{$tanggal} 07:31:00");
            }
        }

        // …hari ini baru satu.
        $this->tapPegawai($pegawai->first(), '2026-09-07 07:31:00');

        $butir = $this->jenis($this->perhatian(), 'kehadiran_anjlok');

        $this->assertCount(1, $butir);
        $this->assertStringContainsString('Baru 1 hadir', $butir[0]['keterangan']);
    }

    #[Test]
    public function unit_yang_kehadirannya_wajar_tidak_dilaporkan(): void
    {
        $pegawai = Pegawai::factory()->count(10)->create(['unit_kerja_id' => $this->upt->id]);

        foreach (['2026-09-04', '2026-09-05', '2026-09-06', '2026-09-07'] as $tanggal) {
            foreach ($pegawai->take(6) as $orang) {
                $this->tapPegawai($orang, "{$tanggal} 07:31:00");
            }
        }

        $this->assertSame([], $this->jenis($this->perhatian(), 'kehadiran_anjlok'));
    }

    #[Test]
    public function unit_kecil_tidak_dibandingkan(): void
    {
        /*
         * Pada unit berisi tiga orang, satu orang cuti sudah berarti turun
         * 33%. Peringatan yang menyala setiap kali seseorang cuti adalah
         * derau, bukan sinyal.
         */
        $pegawai = Pegawai::factory()->count(3)->create(['unit_kerja_id' => $this->upt->id]);

        foreach (['2026-09-04', '2026-09-05', '2026-09-06'] as $tanggal) {
            foreach ($pegawai as $orang) {
                $this->tapPegawai($orang, "{$tanggal} 07:31:00");
            }
        }

        $this->assertSame([], $this->jenis($this->perhatian(), 'kehadiran_anjlok'));
    }

    #[Test]
    public function sebelum_batas_tepat_waktu_kehadiran_belum_dinilai(): void
    {
        /*
         * Pada pukul tujuh pagi setiap unit "anjlok". Peringatan yang selalu
         * menyala pada jam yang sama setiap hari berhenti jadi peringatan.
         */
        $pegawai = Pegawai::factory()->count(10)->create(['unit_kerja_id' => $this->upt->id]);

        foreach (['2026-09-04', '2026-09-05', '2026-09-06'] as $tanggal) {
            foreach ($pegawai->take(6) as $orang) {
                $this->tapPegawai($orang, "{$tanggal} 07:31:00");
            }
        }

        $this->travelTo('2026-09-07 07:00:00');

        $this->assertSame([], $this->jenis($this->perhatian(), 'kehadiran_anjlok'));
    }

    /* ---------------------------------------------------------------------
     * Cakupan peran
     * ------------------------------------------------------------------- */

    #[Test]
    public function admin_upt_tidak_melihat_masalah_unit_lain(): void
    {
        $lain = UnitKerja::factory()->create(['kode' => 'BLK-MLG']);

        Kiosk::factory()->diaktifkan('token-unit-lain')->create([
            'unit_kerja_id' => $lain->id,
            'diaktifkan_pada' => '2026-08-01 08:00:00',
        ]);

        Kiosk::factory()->diaktifkan('token-unit-sendiri')->create([
            'unit_kerja_id' => $this->upt->id,
            'diaktifkan_pada' => '2026-08-01 08:00:00',
        ]);

        $adminUpt = User::factory()->adminUpt($this->upt)->create();

        $milikSendiri = $this->jenis($this->perhatian($adminUpt), 'perangkat_sunyi');

        $this->assertCount(1, $milikSendiri, 'Admin UPT hanya melihat perangkat unitnya sendiri.');
        $this->assertCount(2, $this->jenis($this->perhatian(), 'perangkat_sunyi'));
    }

    #[Test]
    public function perangkat_yang_baru_didaftarkan_belum_dilaporkan(): void
    {
        /*
         * Perangkat yang baru dipasang pagi ini memang belum melayani tap, dan
         * itu bukan kelalaian siapa pun. Tanpa syarat ini, setiap pendaftaran
         * perangkat langsung menyalakan peringatan pada hari yang sama.
         */
        Kiosk::factory()->diaktifkan('token-baru')->create([
            'unit_kerja_id' => $this->upt->id,
            'diaktifkan_pada' => '2026-09-07 08:00:00',
        ]);

        $this->assertSame([], $this->jenis($this->perhatian(), 'perangkat_sunyi'));
    }

    #[Test]
    public function unit_yang_kebiasaannya_terlalu_tipis_tidak_dibandingkan(): void
    {
        /*
         * "Baru 0 hadir hari ini; biasanya sekitar 1" benar secara hitungan
         * dan tidak berguna bagi siapa pun. Rata-rata sekecil itu bukan
         * kebiasaan, melainkan kebetulan.
         */
        $pegawai = Pegawai::factory()->count(10)->create(['unit_kerja_id' => $this->upt->id]);

        foreach (['2026-09-04', '2026-09-05', '2026-09-06'] as $tanggal) {
            $this->tapPegawai($pegawai->first(), "{$tanggal} 07:31:00");
        }

        $this->assertSame([], $this->jenis($this->perhatian(), 'kehadiran_anjlok'));
    }

    #[Test]
    public function jumlah_butir_dibatasi(): void
    {
        foreach (range(1, 9) as $i) {
            EventAbsen::factory()->create([
                'nama' => "Kegiatan Lama {$i}",
                'tanggal' => '2026-09-0'.min(6, $i),
            ])->unitKerja()->attach($this->upt);
        }

        $this->assertLessThanOrEqual(
            PerhatianDashboardService::BATAS_BUTIR,
            count($this->perhatian()),
            'Panel berisi belasan peringatan bukan lagi daftar tindakan, melainkan dinding teks.',
        );
    }

    /* ---------------------------------------------------------------------
     * Bantuan
     * ------------------------------------------------------------------- */

    protected function tap(Kiosk $kiosk, string $waktu): void
    {
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $kiosk->unit_kerja_id]);

        Absensi::query()->create([
            'event_absen_id' => EventAbsen::factory()->umum()->create([
                'tanggal' => substr($waktu, 0, 10),
            ])->id,
            'pegawai_id' => $pegawai->id,
            'kiosk_id' => $kiosk->id,
            'jenis' => JenisAbsen::Datang,
            'metode' => 'manual',
            'waktu' => $waktu,
        ]);
    }

    protected function tapPegawai(Pegawai $pegawai, string $waktu): void
    {
        Absensi::query()->create([
            'event_absen_id' => EventAbsen::factory()->umum()->create([
                'tanggal' => substr($waktu, 0, 10),
            ])->id,
            'pegawai_id' => $pegawai->id,
            'jenis' => JenisAbsen::Datang,
            'metode' => 'manual',
            'waktu' => $waktu,
        ]);
    }
}
