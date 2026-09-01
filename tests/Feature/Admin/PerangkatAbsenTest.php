<?php

namespace Tests\Feature\Admin;

use App\Enums\AksiLog;
use App\Enums\PeranPengguna;
use App\Models\Kiosk;
use App\Models\LogAktivitas;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kelola perangkat absen (FR-USR-02, FR-USR-03).
 */
class PerangkatAbsenTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = '/admin/perangkat';

    /**
     * @return array{upt: UnitKerja, seksi: UnitKerja}
     */
    protected function hirarki(): array
    {
        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $upt = UnitKerja::factory()->create(['kode' => 'BLK-SGS', 'induk_id' => $opd->id]);
        $seksi = UnitKerja::factory()->create(['kode' => 'BLK-SGS-TU', 'induk_id' => $upt->id]);

        return compact('upt', 'seksi');
    }

    #[Test]
    public function pendaftaran_langsung_menerbitkan_kode_aktivasi(): void
    {
        // Perangkat tanpa kode tidak berguna bagi petugas di lokasi.
        ['upt' => $upt] = $this->hirarki();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, ['nama_titik' => 'Aula Utama', 'unit_kerja_id' => $upt->id])
            ->assertRedirect()
            ->assertSessionHas('sukses')
            ->assertSessionHas('kode_aktivasi');

        $perangkat = Kiosk::sole();

        $this->assertSame('Aula Utama', $perangkat->nama_titik);
        $this->assertTrue($perangkat->aktif);
        $this->assertNotNull($perangkat->kode_aktivasi);
        $this->assertTrue($perangkat->kode_aktivasi_kedaluwarsa_at->isFuture());
    }

    #[Test]
    public function nama_titik_tidak_boleh_ganda_dalam_satu_unit(): void
    {
        ['upt' => $upt] = $this->hirarki();
        Kiosk::factory()->create(['nama_titik' => 'Aula Utama', 'unit_kerja_id' => $upt->id]);

        // Dua perangkat bernama sama pada satu unit membuat riwayat aktivasi
        // dan detail event mustahil dibaca.
        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, ['nama_titik' => 'Aula Utama', 'unit_kerja_id' => $upt->id])
            ->assertSessionHasErrors('nama_titik');
    }

    #[Test]
    public function nama_titik_sama_pada_unit_berbeda_diperbolehkan(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $lain = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $upt->induk_id]);

        Kiosk::factory()->create(['nama_titik' => 'Aula Utama', 'unit_kerja_id' => $upt->id]);

        // "Aula Utama" ada di hampir setiap BLK; keunikannya per unit.
        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, ['nama_titik' => 'Aula Utama', 'unit_kerja_id' => $lain->id])
            ->assertSessionHas('sukses');

        $this->assertDatabaseCount('kiosk', 2);
    }

    #[Test]
    public function perangkat_hanya_dapat_ditempatkan_pada_unit_level_teratas(): void
    {
        ['seksi' => $seksi] = $this->hirarki();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, ['nama_titik' => 'Ruang Seksi', 'unit_kerja_id' => $seksi->id])
            ->assertSessionHasErrors('unit_kerja_id');
    }

    #[Test]
    public function kode_aktivasi_baru_dapat_diterbitkan_ulang(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $perangkat = Kiosk::factory()->create(['unit_kerja_id' => $upt->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL."/{$perangkat->id}/kode")
            ->assertSessionHas('kode_aktivasi');

        $this->assertNotNull($perangkat->refresh()->kode_aktivasi);
    }

    #[Test]
    public function cabut_akses_menghapus_device_token(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $perangkat = Kiosk::factory()->diaktifkan('token-uji')->create(['unit_kerja_id' => $upt->id]);

        $this->assertNotNull($perangkat->device_token);

        $this->actingAs(User::factory()->superadmin()->create())
            ->delete(self::URL."/{$perangkat->id}/token")
            ->assertSessionHas('sukses');

        $this->assertNull($perangkat->refresh()->device_token);
    }

    #[Test]
    public function menonaktifkan_perangkat_sekaligus_mencabut_aksesnya(): void
    {
        /*
         * Akun yang dinyatakan tidak dipakai tidak boleh tetap melayani tap
         * sampai seseorang ingat mencabut tokennya secara terpisah.
         */
        ['upt' => $upt] = $this->hirarki();
        $perangkat = Kiosk::factory()->diaktifkan('token-uji')->create(['unit_kerja_id' => $upt->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->patch(self::URL."/{$perangkat->id}/status", ['aktif' => false])
            ->assertSessionHas('sukses');

        $perangkat->refresh();

        $this->assertFalse($perangkat->aktif);
        $this->assertNull($perangkat->device_token);
    }

    #[Test]
    public function perangkat_dinonaktifkan_bukan_dihapus(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $perangkat = Kiosk::factory()->create(['unit_kerja_id' => $upt->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->patch(self::URL."/{$perangkat->id}/status", ['aktif' => false]);

        $this->assertDatabaseCount('kiosk', 1);
    }

    #[Test]
    public function daftar_membawa_jejak_koneksi_terakhir(): void
    {
        // FR-USR-03
        ['upt' => $upt] = $this->hirarki();

        Kiosk::factory()->diaktifkan('token-uji')->create([
            'nama_titik' => 'Aula Utama',
            'unit_kerja_id' => $upt->id,
            'ip_terakhir' => '10.10.4.21',
            'login_terakhir_at' => now()->subMinutes(5),
        ]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Perangkat/Index')
                ->has('daftar', 1)
                ->where('daftar.0.nama_titik', 'Aula Utama')
                ->where('daftar.0.ip_terakhir', '10.10.4.21')
                ->where('daftar.0.terpasang', true)
                ->etc());
    }

    #[Test]
    public function riwayat_memuat_jejak_aktivasi_dan_pencabutan(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $perangkat = Kiosk::factory()->diaktifkan('token-uji')->create(['unit_kerja_id' => $upt->id]);
        $admin = User::factory()->superadmin()->create();

        $this->actingAs($admin)->delete(self::URL."/{$perangkat->id}/token");

        $riwayat = $this->actingAs($admin)
            ->getJson(self::URL."/{$perangkat->id}/riwayat")
            ->assertOk()
            ->json('riwayat');

        $this->assertNotEmpty($riwayat);
        $this->assertSame('Pelepasan Perangkat Absen', $riwayat[0]['aksi']);
        $this->assertSame($admin->nama, $riwayat[0]['oleh']);
    }

    #[Test]
    public function pencabutan_dari_panel_hanya_menghasilkan_satu_catatan_audit(): void
    {
        // Dua catatan untuk satu tindakan hanya membuat audit trail berisik.
        ['upt' => $upt] = $this->hirarki();
        $perangkat = Kiosk::factory()->diaktifkan('token-uji')->create(['unit_kerja_id' => $upt->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->delete(self::URL."/{$perangkat->id}/token");

        $this->assertSame(1, LogAktivitas::aksi(AksiLog::LepasKiosk)->count());
    }

    #[Test]
    public function admin_dinas_dapat_mengelola_perangkat(): void
    {
        // Matriks SRS §6: Admin Dinas "Ya (kiosk saja)".
        ['upt' => $upt] = $this->hirarki();

        $this->actingAs(User::factory()->create(['role' => PeranPengguna::AdminDinas]))
            ->post(self::URL, ['nama_titik' => 'Aula Dinas', 'unit_kerja_id' => $upt->id])
            ->assertSessionHas('sukses');
    }

    #[Test]
    public function admin_upt_tidak_dapat_mengelola_perangkat(): void
    {
        ['upt' => $upt] = $this->hirarki();

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL)
            ->assertForbidden();
    }

    #[Test]
    public function perangkat_dapat_dipindahkan_unitnya(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $lain = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $upt->induk_id]);
        $perangkat = Kiosk::factory()->create(['nama_titik' => 'Aula', 'unit_kerja_id' => $upt->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->patch(self::URL."/{$perangkat->id}", [
                'nama_titik' => 'Aula Timur',
                'unit_kerja_id' => $lain->id,
            ])
            ->assertSessionHas('sukses');

        $perangkat->refresh();

        $this->assertSame('Aula Timur', $perangkat->nama_titik);
        $this->assertSame($lain->id, $perangkat->unit_kerja_id);
    }
}
