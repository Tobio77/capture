<?php

namespace Tests\Feature\Kiosk;

use App\Enums\AksiLog;
use App\Enums\StatusKiosk;
use App\Models\Kiosk;
use App\Models\LogAktivitas;
use App\Models\UnitKerja;
use App\Services\KioskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Akses layar kiosk memakai device_token per perangkat yang dapat dicabut (NFR-03).
 */
class AutentikasiKioskTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-perangkat-uji';

    #[Test]
    public function perangkat_dengan_token_sah_dapat_membuka_layar_kiosk(): void
    {
        $kiosk = $this->kioskAktif();

        $this->denganToken()
            ->get('/kiosk/umum')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Kiosk/Utama')
                ->where('kiosk.nama_titik', $kiosk->nama_titik)
                ->where('kiosk.unit_kerja.kode', 'BLK-SBY')
                ->etc());
    }

    #[Test]
    public function perangkat_tanpa_token_diarahkan_ke_layar_aktivasi(): void
    {
        $this->get('/kiosk/umum')
            ->assertRedirect('/kiosk/aktivasi')
            ->assertSessionHas('gagal');
    }

    #[Test]
    public function token_yang_tidak_dikenal_ditolak(): void
    {
        $this->kioskAktif();

        $this->withCookie(KioskService::NAMA_COOKIE, 'token-palsu')
            ->get('/kiosk/umum')
            ->assertRedirect('/kiosk/aktivasi');
    }

    #[Test]
    public function kiosk_yang_dinonaktifkan_admin_kehilangan_akses(): void
    {
        $kiosk = $this->kioskAktif();

        $this->denganToken()->get('/kiosk/umum')->assertOk();

        $kiosk->update(['aktif' => false]);

        $this->denganToken()->get('/kiosk/umum')->assertRedirect('/kiosk/aktivasi');
    }

    #[Test]
    public function perangkat_yang_sudah_aktif_dialihkan_dari_layar_aktivasi(): void
    {
        $this->kioskAktif();

        $this->denganToken()
            ->get('/kiosk/aktivasi')
            ->assertRedirect('/');
    }

    #[Test]
    public function perangkat_dapat_dilepaskan_dan_tokennya_dicabut(): void
    {
        $kiosk = $this->kioskAktif();

        $respons = $this->denganToken()->post('/kiosk/lepas');

        $respons->assertRedirect('/kiosk/aktivasi');
        $this->assertNull($kiosk->refresh()->device_token);
        $this->assertSame(StatusKiosk::Offline, $kiosk->status);
        $this->assertSame(1, LogAktivitas::aksi(AksiLog::LepasKiosk)->count());

        // Token lama tidak bisa dipakai lagi.
        $this->denganToken()->get('/kiosk/umum')->assertRedirect('/kiosk/aktivasi');
    }

    #[Test]
    public function jejak_ip_diperbarui_saat_perangkat_berpindah_alamat(): void
    {
        $kiosk = $this->kioskAktif();
        $kiosk->forceFill(['ip_terakhir' => '10.10.10.10'])->save();

        $this->denganToken()->get('/kiosk/umum')->assertOk();

        $this->assertSame('127.0.0.1', $kiosk->refresh()->ip_terakhir);
    }

    protected function kioskAktif(): Kiosk
    {
        return Kiosk::factory()->diaktifkan(self::TOKEN)->create([
            'nama_titik' => 'Aula Senam BLK Surabaya',
            'unit_kerja_id' => UnitKerja::factory()->create(['kode' => 'BLK-SBY']),
        ]);
    }

    protected function denganToken(): static
    {
        return $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN);
    }
}
