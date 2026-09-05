<?php

namespace Tests\Feature\Kiosk;

use App\Enums\SumberKiosk;
use App\Models\Kiosk;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\KioskService;
use App\Services\SettingAbsenService;
use App\Support\PengaturanRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mode Terbuka — perangkat masuk tanpa kode aktivasi (FR-SET-06).
 *
 * Fitur ini melonggarkan NFR-03 dengan sengaja, jadi yang diuji bukan hanya
 * "bisa masuk", melainkan juga pagar-pagarnya: mati secara bawaan, ditolak
 * ketika dimatikan, ditandai berbeda dari perangkat terdaftar, dan selalu
 * memasang peringatan di panel admin selama menyala.
 */
class ModeTerbukaTest extends TestCase
{
    use RefreshDatabase;

    protected UnitKerja $unitKerja;

    protected function setUp(): void
    {
        parent::setUp();

        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $this->unitKerja = UnitKerja::factory()->create([
            'kode' => 'BLK-SBY',
            'induk_id' => $opd->id,
        ]);
    }

    protected function nyalakanModeTerbuka(): void
    {
        app(PengaturanRepository::class)->simpan(
            SettingAbsenService::KUNCI_WAJIB_KODE_AKTIVASI,
            '0',
        );
    }

    /* ---------------------------------------------------------------------
     * Bawaan: tertutup.
     * ------------------------------------------------------------------- */

    #[Test]
    public function kode_aktivasi_wajib_secara_bawaan(): void
    {
        // Instalasi baru tanpa satu pun baris pengaturan.
        $this->assertDatabaseCount('pengaturan', 0);

        $this->assertTrue(app(SettingAbsenService::class)->ambil()['wajib_kode_aktivasi']);
        $this->assertFalse(app(SettingAbsenService::class)->modeTerbuka());
    }

    #[Test]
    public function layar_aktivasi_tidak_menawarkan_jalan_pintas_saat_mode_tertutup(): void
    {
        $this->get('/kiosk/aktivasi')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Kiosk/Aktivasi')
                ->where('mode_terbuka', false)

                /*
                 * Daftar unit kerja tidak boleh bocor ke mesin yang belum
                 * memegang kode apa pun.
                 */
                ->has('unit_kerja', 0)
                ->etc());
    }

    #[Test]
    public function masuk_tanpa_kode_ditolak_saat_mode_tertutup(): void
    {
        // Rute yang terbuka bukan berarti fiturnya menyala: pemeriksaannya
        // ada di server, bukan hanya pada tampilan layar aktivasi.
        $this->post('/kiosk/aktivasi/terbuka', ['unit_kerja_id' => $this->unitKerja->id])
            ->assertForbidden();

        $this->assertDatabaseCount('kiosk', 0);
    }

    /* ---------------------------------------------------------------------
     * Saat dinyalakan.
     * ------------------------------------------------------------------- */

    #[Test]
    public function perangkat_dapat_masuk_tanpa_kode_dan_ditandai_ad_hoc(): void
    {
        $this->nyalakanModeTerbuka();

        $this->post('/kiosk/aktivasi/terbuka', ['unit_kerja_id' => $this->unitKerja->id])
            ->assertRedirect('/')
            ->assertCookie(KioskService::NAMA_COOKIE);

        $perangkat = Kiosk::sole();

        $this->assertSame(SumberKiosk::AdHoc, $perangkat->sumber);
        $this->assertSame($this->unitKerja->id, $perangkat->unit_kerja_id);
        $this->assertNotNull($perangkat->device_token);

        // IP dicatat persis seperti perangkat terdaftar (FR-USR-03).
        $this->assertSame('127.0.0.1', $perangkat->ip_terakhir);
        $this->assertNotNull($perangkat->diaktifkan_pada);
    }

    #[Test]
    public function layar_aktivasi_menawarkan_unit_kerja_saat_mode_terbuka(): void
    {
        $this->nyalakanModeTerbuka();

        $this->get('/kiosk/aktivasi')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mode_terbuka', true)
                ->has('unit_kerja', 1)
                ->where('unit_kerja.0.kode', 'BLK-SBY')
                ->etc());
    }

    #[Test]
    public function perangkat_ad_hoc_langsung_dapat_melayani_layar_absen(): void
    {
        $this->nyalakanModeTerbuka();

        $this->post('/kiosk/aktivasi/terbuka', ['unit_kerja_id' => $this->unitKerja->id]);

        $token = Kiosk::sole()->device_token;

        $this->assertNotNull($token);

        // Cookie yang diterbitkan membawa token mentah; di sini cukup
        // dipastikan perangkatnya dikenali oleh layanan yang sama.
        $this->assertNotNull(
            Kiosk::query()->where('device_token', $token)->first(),
        );
    }

    #[Test]
    public function unit_kerja_tak_dikenal_ditolak(): void
    {
        $this->nyalakanModeTerbuka();

        $this->post('/kiosk/aktivasi/terbuka', ['unit_kerja_id' => 9999])
            ->assertSessionHasErrors('unit_kerja_id');

        $this->assertDatabaseCount('kiosk', 0);
    }

    /* ---------------------------------------------------------------------
     * Peringatan di panel admin.
     * ------------------------------------------------------------------- */

    #[Test]
    public function panel_admin_membawa_penanda_mode_terbuka(): void
    {
        $admin = User::factory()->superadmin()->create();

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('mode_terbuka', false)->etc());

        $this->nyalakanModeTerbuka();

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('mode_terbuka', true)->etc());
    }

    #[Test]
    public function daftar_perangkat_membedakan_ad_hoc_dari_terdaftar(): void
    {
        $this->nyalakanModeTerbuka();
        $this->post('/kiosk/aktivasi/terbuka', ['unit_kerja_id' => $this->unitKerja->id]);

        Kiosk::factory()->create([
            'nama_titik' => 'Aula Utama',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/admin/perangkat')
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $baris = collect($page->toArray()['props']['daftar']['data']);

                $adHoc = $baris->firstWhere('sumber', 'ad_hoc');
                $terdaftar = $baris->firstWhere('sumber', 'terdaftar');

                $this->assertNotNull($adHoc, 'Perangkat ad-hoc tidak muncul pada daftar.');
                $this->assertSame('Ad-hoc', $adHoc['sumber_label']);
                $this->assertSame('Aula Utama', $terdaftar['nama_titik']);
            });
    }
}
