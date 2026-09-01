<?php

namespace Tests\Feature\Admin;

use App\Enums\JenisAbsen;
use App\Enums\MetodeAbsen;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\EventAbsenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dashboard statistik dan tren kehadiran (FR-DASH-01, FR-DASH-02).
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{upt: UnitKerja, lain: UnitKerja, seksi: UnitKerja}
     */
    protected function hirarki(): array
    {
        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $upt = UnitKerja::factory()->create(['kode' => 'BLK-SGS', 'induk_id' => $opd->id]);
        $lain = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $opd->id]);
        $seksi = UnitKerja::factory()->create(['kode' => 'BLK-SGS-TU', 'induk_id' => $upt->id]);

        return compact('upt', 'lain', 'seksi');
    }

    #[Test]
    public function kartu_statistik_menghitung_kehadiran_hari_ini(): void
    {
        ['upt' => $upt] = $this->hirarki();

        $pegawai = Pegawai::factory()->count(4)->create(['unit_kerja_id' => $upt->id]);
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($upt);

        // Dua dari empat pegawai hadir hari ini.
        foreach ($pegawai->take(2) as $orang) {
            Absensi::factory()->create([
                'event_absen_id' => $event->id,
                'pegawai_id' => $orang->id,
                'waktu' => now(),
            ]);
        }

        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('statistik.total_pegawai', 4)
                ->where('statistik.hadir_hari_ini', 2)
                // JSON menyerahkan 50.0 sebagai 50; nilai desimalnya diuji
                // langsung pada service di test lain.
                ->where('statistik.persentase_kehadiran', 50)
                ->where('statistik.event_berlangsung', 1)
                ->etc());
    }

    #[Test]
    public function kehadiran_dihitung_per_pegawai_bukan_per_baris_absensi(): void
    {
        ['upt' => $upt] = $this->hirarki();

        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);
        $event = EventAbsen::factory()->create();

        // Satu orang datang lalu pulang: dua baris, tetap satu kehadiran.
        Absensi::factory()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => $pegawai->id,
            'jenis' => JenisAbsen::Datang,
            'waktu' => now(),
        ]);
        Absensi::factory()->pulang()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => $pegawai->id,
            'waktu' => now(),
        ]);

        $statistik = app(DashboardService::class)->statistik(User::factory()->superadmin()->create());

        $this->assertSame(1, $statistik['hadir_hari_ini']);
    }

    #[Test]
    public function admin_upt_hanya_melihat_angka_unitnya(): void
    {
        ['upt' => $upt, 'lain' => $lain, 'seksi' => $seksi] = $this->hirarki();

        // Pegawai unitnya sendiri, termasuk seksi di bawahnya.
        Pegawai::factory()->count(2)->create(['unit_kerja_id' => $upt->id]);
        Pegawai::factory()->count(3)->create(['unit_kerja_id' => $seksi->id]);
        Pegawai::factory()->count(7)->create(['unit_kerja_id' => $lain->id]);

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get('/admin/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                // 2 + 3, bukan 12: turunan ikut, unit lain tidak.
                ->where('statistik.total_pegawai', 5)
                ->etc());
    }

    #[Test]
    public function kehadiran_unit_lain_tidak_ikut_terhitung(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();

        $milikSendiri = Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);
        $milikLain = Pegawai::factory()->create(['unit_kerja_id' => $lain->id]);
        $event = EventAbsen::factory()->create();

        foreach ([$milikSendiri, $milikLain] as $orang) {
            Absensi::factory()->create([
                'event_absen_id' => $event->id,
                'pegawai_id' => $orang->id,
                'waktu' => now(),
            ]);
        }

        $statistik = app(DashboardService::class)
            ->statistik(User::factory()->adminUpt($upt)->create());

        $this->assertSame(1, $statistik['hadir_hari_ini']);
        $this->assertSame(100.0, $statistik['persentase_kehadiran']);
    }

    #[Test]
    public function kiosk_aktif_menghitung_yang_benar_benar_melayani_event(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($upt);

        $melayani = Kiosk::factory()->create(['unit_kerja_id' => $upt->id]);
        Kiosk::factory()->create(['unit_kerja_id' => $upt->id]);

        app(EventAbsenService::class)->catatKioskAktif($event, $melayani, '10.10.4.21');

        $statistik = app(DashboardService::class)->statistik(User::factory()->superadmin()->create());

        // Dua kiosk terdaftar, tetapi hanya satu yang benar-benar melayani.
        $this->assertSame(1, $statistik['kiosk_aktif']);
    }

    #[Test]
    public function tren_membawa_tujuh_hari_termasuk_hari_kosong(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);
        $event = EventAbsen::factory()->create();

        Absensi::factory()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => $pegawai->id,
            'waktu' => now()->subDays(2),
        ]);

        $tren = app(DashboardService::class)->tren(User::factory()->superadmin()->create());

        $this->assertCount(DashboardService::HARI_TREN, $tren);

        // Hari tanpa kehadiran tetap muncul dengan nilai nol agar grafiknya
        // tidak melompati tanggal.
        $this->assertSame(0, $tren[0]['jumlah']);
        $this->assertSame(1, $tren[DashboardService::HARI_TREN - 3]['jumlah']);
        $this->assertSame(now()->toDateString(), $tren[DashboardService::HARI_TREN - 1]['tanggal']);
    }

    #[Test]
    public function absensi_lebih_lama_dari_rentang_tidak_ikut_pada_tren(): void
    {
        $pegawai = Pegawai::factory()->create();
        $event = EventAbsen::factory()->create();

        Absensi::factory()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => $pegawai->id,
            'waktu' => now()->subDays(DashboardService::HARI_TREN + 1),
        ]);

        $tren = app(DashboardService::class)->tren(User::factory()->superadmin()->create());

        $this->assertSame(0, collect($tren)->sum('jumlah'));
    }

    #[Test]
    public function aktivitas_terbaru_diurutkan_menurut_waktu_tap(): void
    {
        // FR-DASH-03. Absen yang tertahan antrian luring baru tersimpan
        // belakangan, tetapi kejadiannya lebih dulu — urutan mengikuti waktu
        // tap, bukan waktu penyimpanan.
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create();

        $awal = Pegawai::factory()->create(['nama' => 'Ahmad Fauzi', 'unit_kerja_id' => $upt->id]);
        $akhir = Pegawai::factory()->create(['nama' => 'Dewi Anggraini', 'unit_kerja_id' => $upt->id]);

        // Dibuat terbalik dari urutan kejadiannya.
        Absensi::factory()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => $awal->id,
            'waktu' => now()->subMinutes(30),
        ]);
        Absensi::factory()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => $akhir->id,
            'waktu' => now()->subMinutes(5),
        ]);

        $aktivitas = app(DashboardService::class)
            ->aktivitasTerbaru(User::factory()->superadmin()->create());

        $this->assertSame('Dewi Anggraini', $aktivitas[0]['nama']);
        $this->assertSame('Ahmad Fauzi', $aktivitas[1]['nama']);
    }

    #[Test]
    public function aktivitas_membawa_metode_dan_status_ketepatan(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);

        Absensi::factory()->terlambat()->create([
            'event_absen_id' => EventAbsen::factory()->create()->id,
            'pegawai_id' => $pegawai->id,
            'metode' => MetodeAbsen::Rfid,
        ]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/admin/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('aktivitas', 1)
                ->where('aktivitas.0.metode', 'rfid')
                ->where('aktivitas.0.metode_label', 'Tap RFID')
                ->where('aktivitas.0.status_ketepatan', 'terlambat')
                ->etc());
    }

    #[Test]
    public function aktivitas_admin_upt_terbatas_pada_unitnya(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $event = EventAbsen::factory()->create();

        Absensi::factory()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => Pegawai::factory()->create(['unit_kerja_id' => $upt->id])->id,
        ]);
        Absensi::factory()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => Pegawai::factory()->create(['unit_kerja_id' => $lain->id])->id,
        ]);

        $aktivitas = app(DashboardService::class)
            ->aktivitasTerbaru(User::factory()->adminUpt($upt)->create());

        $this->assertCount(1, $aktivitas);
    }

    #[Test]
    public function aktivitas_dibatasi_panjangnya(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create();

        foreach (range(1, DashboardService::BATAS_AKTIVITAS + 5) as $urutan) {
            Absensi::factory()->create([
                'event_absen_id' => $event->id,
                'pegawai_id' => Pegawai::factory()->create(['unit_kerja_id' => $upt->id])->id,
                'waktu' => now()->subMinutes($urutan),
            ]);
        }

        $aktivitas = app(DashboardService::class)
            ->aktivitasTerbaru(User::factory()->superadmin()->create());

        $this->assertCount(DashboardService::BATAS_AKTIVITAS, $aktivitas);
    }

    #[Test]
    public function endpoint_aktivitas_menjawab_json_untuk_pembaruan_berkala(): void
    {
        ['upt' => $upt] = $this->hirarki();

        Absensi::factory()->create([
            'event_absen_id' => EventAbsen::factory()->create()->id,
            'pegawai_id' => Pegawai::factory()->create([
                'nama' => 'Ahmad Fauzi',
                'unit_kerja_id' => $upt->id,
            ])->id,
        ]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->getJson('/admin/dashboard/aktivitas')
            ->assertOk()
            ->assertJson(['aktivitas' => [['nama' => 'Ahmad Fauzi']]]);
    }

    #[Test]
    public function endpoint_aktivitas_tertutup_bagi_tamu(): void
    {
        $this->getJson('/admin/dashboard/aktivitas')->assertUnauthorized();
    }

    #[Test]
    public function tanpa_pegawai_persentase_nol_bukan_pembagian_dengan_nol(): void
    {
        $statistik = app(DashboardService::class)->statistik(User::factory()->superadmin()->create());

        $this->assertSame(0, $statistik['total_pegawai']);
        $this->assertSame(0.0, $statistik['persentase_kehadiran']);
    }
}
