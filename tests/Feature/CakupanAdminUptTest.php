<?php

namespace Tests\Feature;

use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\AbsensiService;
use App\Services\AbsenUmumService;
use App\Services\KioskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Admin UPT hanya melihat data unit kerjanya sendiri — absensi maupun
 * perangkatnya — sementara Superadmin dan Admin Dinas melihat seluruh dinas.
 * Daftar e-Presensi juga menyebut unit kerja tiap pegawai.
 */
class CakupanAdminUptTest extends TestCase
{
    use RefreshDatabase;

    protected UnitKerja $upt;

    protected UnitKerja $uptLain;

    protected Pegawai $pegawaiUpt;

    protected Pegawai $pegawaiLain;

    protected EventAbsen $event;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AbsensiService::DISK);
        Carbon::setTestNow('2026-09-07 07:00:00');

        $this->upt = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'nama' => 'UPT BLK Surabaya']);
        $this->uptLain = UnitKerja::factory()->create(['kode' => 'BLK-MJK', 'nama' => 'UPT BLK Mojokerto']);

        $this->pegawaiUpt = Pegawai::factory()->create(['unit_kerja_id' => $this->upt->id]);
        $this->pegawaiLain = Pegawai::factory()->create(['unit_kerja_id' => $this->uptLain->id]);

        $this->event = EventAbsen::factory()->create(['tanggal' => '2026-09-07', 'jam_mulai' => '07:30']);

        foreach ([[$this->upt, $this->pegawaiUpt], [$this->uptLain, $this->pegawaiLain]] as [$unit, $pegawai]) {
            $kiosk = Kiosk::factory()->create(['unit_kerja_id' => $unit->id]);
            $this->gabungkanKeEvent($this->event, $kiosk);

            Absensi::factory()->create([
                'event_absen_id' => $this->event->id,
                'pegawai_id' => $pegawai->id,
                'kiosk_id' => $kiosk->id,
            ]);
        }
    }

    protected function adminUpt(): User
    {
        return User::factory()->adminUpt($this->upt)->create();
    }

    #[Test]
    public function daftar_event_admin_upt_hanya_menghitung_unitnya(): void
    {
        $this->actingAs($this->adminUpt())
            ->get('/admin/kelola-absen/event')
            ->assertInertia(fn (Assert $page) => $page
                ->where('daftar.data.0.jumlah_absensi', 1)
                ->where('daftar.data.0.jumlah_kiosk', 1));

        $this->actingAs(User::factory()->adminDinas()->create())
            ->get('/admin/kelola-absen/event')
            ->assertInertia(fn (Assert $page) => $page
                ->where('daftar.data.0.jumlah_absensi', 2)
                ->where('daftar.data.0.jumlah_kiosk', 2));
    }

    #[Test]
    public function detail_event_admin_upt_hanya_memuat_perangkat_unitnya(): void
    {
        $jawaban = $this->actingAs($this->adminUpt())
            ->getJson("/admin/kelola-absen/event/{$this->event->id}/detail")
            ->assertOk();

        $this->assertSame(1, $jawaban->json('jumlah_absensi'));
        $this->assertCount(1, $jawaban->json('kiosk'));
        $this->assertSame('UPT BLK Surabaya', $jawaban->json('kiosk.0.unit_kerja_nama'));

        $semua = $this->actingAs(User::factory()->superadmin()->create())
            ->getJson("/admin/kelola-absen/event/{$this->event->id}/detail");

        $this->assertSame(2, $semua->json('jumlah_absensi'));
        $this->assertCount(2, $semua->json('kiosk'));
    }

    #[Test]
    public function layar_absen_umum_admin_upt_hanya_menampilkan_pegawai_unitnya(): void
    {
        $sesi = app(AbsenUmumService::class)->sesi(buat: true);

        foreach ([$this->pegawaiUpt, $this->pegawaiLain] as $pegawai) {
            Absensi::factory()->create([
                'event_absen_id' => $sesi->id,
                'pegawai_id' => $pegawai->id,
                'kiosk_id' => null,
            ]);
        }

        $daftar = $this->actingAs($this->adminUpt())
            ->getJson('/admin/kelola-absen/absen-umum/presensi')
            ->assertOk()
            ->json('daftar_presensi');

        $this->assertSame([$this->pegawaiUpt->nip], array_column($daftar, 'nip'));
        $this->assertSame('UPT BLK Surabaya', $daftar[0]['unit_kerja']);

        $semua = $this->actingAs(User::factory()->adminDinas()->create())
            ->getJson('/admin/kelola-absen/absen-umum/presensi')
            ->json('daftar_presensi');

        $this->assertCount(2, $semua);
    }

    #[Test]
    public function perangkat_absen_menampilkan_seluruh_pegawai_beserta_unit_kerjanya(): void
    {
        Kiosk::factory()->diaktifkan('token-cakupan')->create(['unit_kerja_id' => $this->upt->id]);

        $daftar = $this->withCookie(KioskService::NAMA_COOKIE, 'token-cakupan')
            ->get('/kiosk/event/presensi', ['Accept' => 'application/json'])
            ->assertOk()
            ->json('daftar_presensi');

        $this->assertEqualsCanonicalizing(
            ['UPT BLK Surabaya', 'UPT BLK Mojokerto'],
            array_column($daftar, 'unit_kerja'),
        );
    }

    #[Test]
    public function admin_upt_tidak_dapat_membuka_foto_absen_pegawai_unit_lain(): void
    {
        $sesi = app(AbsenUmumService::class)->sesi(buat: true);
        Storage::disk(AbsensiService::DISK)->put('absen/lain.jpg', 'foto');
        Storage::disk(AbsensiService::DISK)->put('absen/sendiri.jpg', 'foto');

        $lain = Absensi::factory()->create([
            'event_absen_id' => $sesi->id,
            'pegawai_id' => $this->pegawaiLain->id,
            'kiosk_id' => null,
            'foto_path' => 'absen/lain.jpg',
        ]);
        $sendiri = Absensi::factory()->create([
            'event_absen_id' => $sesi->id,
            'pegawai_id' => $this->pegawaiUpt->id,
            'kiosk_id' => null,
            'foto_path' => 'absen/sendiri.jpg',
        ]);

        $admin = $this->adminUpt();

        $this->actingAs($admin)->get("/admin/kelola-absen/absen-umum/absen/{$lain->id}/foto")->assertForbidden();
        $this->actingAs($admin)->get("/admin/kelola-absen/absen-umum/absen/{$sendiri->id}/foto")->assertOk();
    }
}
