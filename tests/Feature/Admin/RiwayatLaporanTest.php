<?php

namespace Tests\Feature\Admin;

use App\Enums\StatusRiwayatLaporan;
use App\Models\RiwayatLaporan;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Riwayat Generate Laporan Resmi (FR-LAP-04, revisi antrian) — lihat status,
 * unduh berkas, dan hapus baris. Cakupannya keyed atas SIAPA YANG MEMINTA
 * (user_id), bukan cakupan unit kerja seperti Laporan/Rekap lainnya, jadi
 * pengujiannya juga terpisah dari LaporanTest.
 */
class RiwayatLaporanTest extends TestCase
{
    use RefreshDatabase;

    protected function buatRiwayat(User $pemohon, array $atribut = []): RiwayatLaporan
    {
        return RiwayatLaporan::query()->create(array_merge([
            'user_id' => $pemohon->id,
            'format' => 'pdf',
            'dari' => '2026-09-01',
            'sampai' => '2026-09-30',
            'unit_kerja_id' => null,
            'status' => StatusRiwayatLaporan::Selesai,
            'nama_berkas' => 'laporan-resmi-uji.pdf',
            'path' => null,
        ], $atribut));
    }

    #[Test]
    public function admin_upt_hanya_melihat_riwayat_miliknya_sendiri(): void
    {
        $upt = UnitKerja::factory()->create();
        $adminUpt = User::factory()->adminUpt($upt)->create();
        $adminUptLain = User::factory()->adminUpt($upt)->create();

        $this->buatRiwayat($adminUpt, ['nama_berkas' => 'punya-saya.pdf']);
        $this->buatRiwayat($adminUptLain, ['nama_berkas' => 'punya-orang-lain.pdf']);

        $jawaban = $this->actingAs($adminUpt)
            ->getJson('/admin/laporan/riwayat')
            ->assertOk();

        $daftar = $jawaban->json('riwayat');
        $this->assertCount(1, $daftar);
        $this->assertNull($daftar[0]['dibuat_oleh']);
    }

    #[Test]
    public function superadmin_melihat_riwayat_milik_semua_orang(): void
    {
        $upt = UnitKerja::factory()->create();
        $adminUpt = User::factory()->adminUpt($upt)->create(['nama' => 'Budi Admin UPT']);
        $superadmin = User::factory()->superadmin()->create();

        $this->buatRiwayat($adminUpt);
        $this->buatRiwayat($superadmin);

        $daftar = $this->actingAs($superadmin)
            ->getJson('/admin/laporan/riwayat')
            ->assertOk()
            ->json('riwayat');

        $this->assertCount(2, $daftar);
        $this->assertContains('Budi Admin UPT', array_column($daftar, 'dibuat_oleh'));
    }

    #[Test]
    public function admin_dinas_tidak_ikut_melihat_riwayat_orang_lain(): void
    {
        // Sengaja beda dari cakupan "lintasUnit()" biasa: admin dinas
        // lintas unit untuk Laporan/Rekap, tetapi TIDAK untuk Riwayat —
        // hanya superadmin yang bertanggung jawab atas kebersihannya.
        $upt = UnitKerja::factory()->create();
        $adminUpt = User::factory()->adminUpt($upt)->create();
        $adminDinas = User::factory()->adminDinas()->create();

        $this->buatRiwayat($adminUpt);

        $daftar = $this->actingAs($adminDinas)
            ->getJson('/admin/laporan/riwayat')
            ->assertOk()
            ->json('riwayat');

        $this->assertCount(0, $daftar);
    }

    #[Test]
    public function unduh_menolak_yang_bukan_pemilik_maupun_superadmin(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('laporan-resmi/x.pdf', '%PDF-uji');

        $upt = UnitKerja::factory()->create();
        $pemilik = User::factory()->adminUpt($upt)->create();
        $bukanPemilik = User::factory()->adminUpt($upt)->create();

        $riwayat = $this->buatRiwayat($pemilik, ['path' => 'laporan-resmi/x.pdf']);

        $this->actingAs($bukanPemilik)
            ->get("/admin/laporan/riwayat/{$riwayat->id}/unduh")
            ->assertForbidden();
    }

    #[Test]
    public function unduh_menolak_yang_belum_selesai(): void
    {
        Storage::fake('local');

        $upt = UnitKerja::factory()->create();
        $pemilik = User::factory()->adminUpt($upt)->create();

        $riwayat = $this->buatRiwayat($pemilik, ['status' => StatusRiwayatLaporan::Diproses, 'path' => null]);

        $this->actingAs($pemilik)
            ->get("/admin/laporan/riwayat/{$riwayat->id}/unduh")
            ->assertNotFound();
    }

    #[Test]
    public function unduh_mengembalikan_berkas_bagi_pemiliknya(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('laporan-resmi/x.pdf', '%PDF-uji');

        $upt = UnitKerja::factory()->create();
        $pemilik = User::factory()->adminUpt($upt)->create();

        $riwayat = $this->buatRiwayat($pemilik, ['path' => 'laporan-resmi/x.pdf']);

        $this->actingAs($pemilik)
            ->get("/admin/laporan/riwayat/{$riwayat->id}/unduh")
            ->assertOk()
            ->assertDownload($riwayat->nama_berkas);
    }

    #[Test]
    public function superadmin_dapat_mengunduh_riwayat_milik_siapa_pun(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('laporan-resmi/x.pdf', '%PDF-uji');

        $upt = UnitKerja::factory()->create();
        $pemilik = User::factory()->adminUpt($upt)->create();
        $superadmin = User::factory()->superadmin()->create();

        $riwayat = $this->buatRiwayat($pemilik, ['path' => 'laporan-resmi/x.pdf']);

        $this->actingAs($superadmin)
            ->get("/admin/laporan/riwayat/{$riwayat->id}/unduh")
            ->assertOk();
    }

    #[Test]
    public function hapus_menolak_yang_bukan_pemilik_maupun_superadmin(): void
    {
        $upt = UnitKerja::factory()->create();
        $pemilik = User::factory()->adminUpt($upt)->create();
        $bukanPemilik = User::factory()->adminUpt($upt)->create();

        $riwayat = $this->buatRiwayat($pemilik);

        $this->actingAs($bukanPemilik)
            ->delete("/admin/laporan/riwayat/{$riwayat->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('riwayat_laporan', ['id' => $riwayat->id]);
    }

    #[Test]
    public function hapus_menghapus_baris_beserta_berkasnya(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('laporan-resmi/x.pdf', '%PDF-uji');

        $upt = UnitKerja::factory()->create();
        $pemilik = User::factory()->adminUpt($upt)->create();

        $riwayat = $this->buatRiwayat($pemilik, ['path' => 'laporan-resmi/x.pdf']);

        $this->actingAs($pemilik)
            ->delete("/admin/laporan/riwayat/{$riwayat->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('riwayat_laporan', ['id' => $riwayat->id]);
        Storage::disk('local')->assertMissing('laporan-resmi/x.pdf');
    }

    #[Test]
    public function superadmin_dapat_menghapus_riwayat_milik_siapa_pun(): void
    {
        $upt = UnitKerja::factory()->create();
        $pemilik = User::factory()->adminUpt($upt)->create();
        $superadmin = User::factory()->superadmin()->create();

        $riwayat = $this->buatRiwayat($pemilik);

        $this->actingAs($superadmin)
            ->delete("/admin/laporan/riwayat/{$riwayat->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('riwayat_laporan', ['id' => $riwayat->id]);
    }
}
