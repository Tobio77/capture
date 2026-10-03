<?php

namespace Tests\Feature;

use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\KioskService;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sakelar fitur Absen Umum dan Absen Event di Setting Absen.
 *
 * Fitur yang dinonaktifkan tertutup sepenuhnya — layar dan setiap
 * endpoint-nya — sementara fitur yang lain tetap berjalan seperti biasa.
 */
class SakelarFiturAbsenTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-perangkat-sakelar';

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-07 07:00:00');

        $unit = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);
        Kiosk::factory()->diaktifkan(self::TOKEN)->create(['unit_kerja_id' => $unit->id]);

        $event = EventAbsen::factory()->create(['tanggal' => '2026-09-07', 'jam_mulai' => '07:30']);
        $event->unitKerja()->attach($unit);

        $this->admin = User::factory()->superadmin()->create();

        $this->atur(umum: true, event: true);
    }

    protected function atur(bool $umum, bool $event): void
    {
        app(SettingAbsenService::class)->simpan([
            'absen_umum_aktif' => $umum,
            'absen_event_aktif' => $event,
        ], $this->admin);
    }

    protected function perangkat(): static
    {
        return $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN);
    }

    #[Test]
    public function kedua_fitur_menyala_secara_bawaan(): void
    {
        $setting = app(SettingAbsenService::class)->ambil();

        $this->assertTrue($setting['absen_event_aktif']);
        $this->perangkat()->get('/kiosk/umum')->assertOk();
        $this->perangkat()->get('/kiosk/event')->assertOk();
    }

    #[Test]
    public function absen_umum_nonaktif_menutup_layar_dan_endpoint_perangkat(): void
    {
        $this->atur(umum: false, event: true);

        $this->perangkat()->get('/kiosk/umum')
            ->assertRedirect('/')
            ->assertSessionHas('gagal', 'Fitur Absen Umum sedang dinonaktifkan oleh admin.');

        $this->perangkat()->withCredentials()->getJson('/kiosk/umum/presensi')
            ->assertForbidden()
            ->assertJson(['success' => false, 'code' => 'FITUR_NONAKTIF']);

        $this->perangkat()->withCredentials()->postJson('/kiosk/umum/tap/identifikasi', ['id_card' => '199001012020011001'])
            ->assertForbidden()
            ->assertJsonPath('code', 'FITUR_NONAKTIF');

        $this->perangkat()->withCredentials()->postJson('/kiosk/umum/absen', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'FITUR_NONAKTIF');

        // Absen Event tidak ikut tertutup.
        $this->perangkat()->get('/kiosk/event')->assertOk();
    }

    #[Test]
    public function absen_event_nonaktif_menutup_layar_dan_endpoint_perangkat(): void
    {
        $this->atur(umum: true, event: false);

        $this->perangkat()->get('/kiosk/event')
            ->assertRedirect('/')
            ->assertSessionHas('gagal', 'Fitur Absen Event sedang dinonaktifkan oleh admin.');

        $this->perangkat()->withCredentials()->getJson('/kiosk/event/presensi')
            ->assertForbidden()
            ->assertJsonPath('code', 'FITUR_NONAKTIF');

        $this->perangkat()->withCredentials()->postJson('/kiosk/event/absen', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'FITUR_NONAKTIF');

        $this->perangkat()->get('/kiosk/umum')->assertOk();
    }

    #[Test]
    public function absen_umum_nonaktif_menutup_layar_absen_admin_tetapi_bukan_pemantauannya(): void
    {
        $this->atur(umum: false, event: true);

        $this->actingAs($this->admin)
            ->get('/admin/kelola-absen/absen-umum/layar')
            ->assertRedirect(route('absen-umum.index'));

        $this->actingAs($this->admin)
            ->postJson('/admin/kelola-absen/absen-umum/absen', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'FITUR_NONAKTIF');

        $this->actingAs($this->admin)->get('/admin/kelola-absen/absen-umum')->assertOk();
        $this->actingAs($this->admin)->get('/admin/kelola-absen/event')->assertOk();
    }

    #[Test]
    public function beranda_mengirim_status_kedua_fitur(): void
    {
        $this->atur(umum: false, event: false);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Beranda')
            ->where('absen_umum_aktif', false)
            ->where('absen_event_aktif', false));
    }

    #[Test]
    public function menyalakan_kembali_membuka_fiturnya(): void
    {
        $this->atur(umum: false, event: false);
        $this->atur(umum: true, event: true);

        $this->perangkat()->get('/kiosk/umum')->assertOk();
        $this->perangkat()->get('/kiosk/event')->assertOk();
    }
}
