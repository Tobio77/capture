<?php

namespace Tests\Feature\Admin;

use App\Enums\AksiLog;
use App\Enums\PeranPengguna;
use App\Models\Kiosk;
use App\Models\LogAktivitas;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Setting Unit Kerja (FR-UNIT-01, FR-UNIT-02).
 */
class UnitKerjaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function daftar_menampilkan_jumlah_pegawai_dan_kiosk_per_unit(): void
    {
        $unitKerja = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'nama' => 'UPT BLK Surabaya']);
        Pegawai::factory()->count(3)->create(['unit_kerja_id' => $unitKerja->id]);
        Kiosk::factory()->count(2)->create(['unit_kerja_id' => $unitKerja->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/admin/kelola-absen/unit-kerja')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('UnitKerja/Index')
                ->where('dapat_mengubah', true)
                ->has('daftar', 1)
                ->where('daftar.0.kode', 'BLK-SBY')
                ->where('daftar.0.jumlah_pegawai', 3)
                ->where('daftar.0.jumlah_kiosk', 2)
                ->etc());
    }

    #[Test]
    public function superadmin_dapat_menambah_unit_kerja(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/admin/kelola-absen/unit-kerja', [
                'kode' => 'blk-jbr',
                'nama' => 'UPT Balai Latihan Kerja Jember',
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        // Kode selalu disimpan dalam huruf kapital.
        $this->assertDatabaseHas('unit_kerja', [
            'kode' => 'BLK-JBR',
            'nama' => 'UPT Balai Latihan Kerja Jember',
            'aktif' => true,
        ]);
    }

    #[Test]
    public function admin_dinas_dapat_mengubah_unit_kerja(): void
    {
        $unitKerja = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'nama' => 'BLK Surabaya']);

        $this->actingAs(User::factory()->create(['role' => PeranPengguna::AdminDinas]))
            ->patch("/admin/kelola-absen/unit-kerja/{$unitKerja->id}", [
                'kode' => 'BLK-SBY',
                'nama' => 'UPT Balai Latihan Kerja Surabaya',
            ])
            ->assertSessionHas('sukses');

        $this->assertSame('UPT Balai Latihan Kerja Surabaya', $unitKerja->refresh()->nama);
    }

    #[Test]
    public function unit_kerja_dinonaktifkan_bukan_dihapus(): void
    {
        $unitKerja = UnitKerja::factory()->create(['kode' => 'UPT-K3']);
        Pegawai::factory()->create(['unit_kerja_id' => $unitKerja->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->patch("/admin/kelola-absen/unit-kerja/{$unitKerja->id}/status", ['aktif' => false])
            ->assertSessionHas('sukses');

        $this->assertFalse($unitKerja->refresh()->aktif);
        $this->assertDatabaseCount('unit_kerja', 1);
        $this->assertDatabaseCount('pegawai', 1);
    }

    #[Test]
    public function kode_unit_kerja_harus_unik(): void
    {
        UnitKerja::factory()->create(['kode' => 'BLK-SBY']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/admin/kelola-absen/unit-kerja', ['kode' => 'BLK-SBY', 'nama' => 'Unit Kembar'])
            ->assertSessionHasErrors('kode');

        $this->assertDatabaseCount('unit_kerja', 1);
    }

    #[Test]
    public function unit_kerja_boleh_menyimpan_kodenya_sendiri_saat_diubah(): void
    {
        $unitKerja = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->patch("/admin/kelola-absen/unit-kerja/{$unitKerja->id}", [
                'kode' => 'BLK-SBY',
                'nama' => 'Nama Baru',
            ])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function kode_dengan_karakter_tidak_sah_ditolak(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/admin/kelola-absen/unit-kerja', ['kode' => 'BLK SBY!', 'nama' => 'Unit Uji'])
            ->assertSessionHasErrors('kode');
    }

    #[Test]
    public function pesan_validasi_memakai_bahasa_indonesia(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/admin/kelola-absen/unit-kerja', ['kode' => '', 'nama' => ''])
            ->assertSessionHasErrors([
                'kode' => 'Kolom kode unit kerja wajib diisi.',
                'nama' => 'Kolom nama unit kerja wajib diisi.',
            ]);
    }

    #[Test]
    public function admin_upt_dapat_melihat_daftar_tanpa_hak_ubah(): void
    {
        UnitKerja::factory()->create(['kode' => 'BLK-SBY']);

        $this->actingAs(User::factory()->adminUpt()->create())
            ->get('/admin/kelola-absen/unit-kerja')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('dapat_mengubah', false)->etc());
    }

    #[Test]
    public function admin_upt_ditolak_pada_seluruh_aksi_perubahan(): void
    {
        $unitKerja = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);
        $adminUpt = User::factory()->adminUpt($unitKerja)->create();

        $this->actingAs($adminUpt)
            ->post('/admin/kelola-absen/unit-kerja', ['kode' => 'BARU', 'nama' => 'Unit Baru'])
            ->assertForbidden();

        $this->actingAs($adminUpt)
            ->patch("/admin/kelola-absen/unit-kerja/{$unitKerja->id}", ['kode' => 'BLK-SBY', 'nama' => 'Diubah'])
            ->assertForbidden();

        $this->actingAs($adminUpt)
            ->patch("/admin/kelola-absen/unit-kerja/{$unitKerja->id}/status", ['aktif' => false])
            ->assertForbidden();

        $this->assertDatabaseCount('unit_kerja', 1);
        $this->assertTrue($unitKerja->refresh()->aktif);
    }

    #[Test]
    public function perubahan_unit_kerja_tercatat_pada_audit_trail(): void
    {
        $pelaku = User::factory()->superadmin()->create();

        $this->actingAs($pelaku)->post('/admin/kelola-absen/unit-kerja', [
            'kode' => 'BLK-JBR',
            'nama' => 'UPT BLK Jember',
        ]);

        $log = LogAktivitas::aksi(AksiLog::Buat)->sole();
        $unitKerja = UnitKerja::sole();

        $this->assertSame($pelaku->id, $log->user_id);
        $this->assertTrue($log->subjek->is($unitKerja));
        $this->assertStringContainsString('BLK-JBR', $log->deskripsi);
    }
}
