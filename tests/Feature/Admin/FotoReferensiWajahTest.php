<?php

namespace Tests\Feature\Admin;

use App\Enums\AksiLog;
use App\Models\LogAktivitas;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\FotoReferensiWajahService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pendaftaran foto referensi wajah pegawai (FR-PEG-05, NFR-04).
 */
class FotoReferensiWajahTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(FotoReferensiWajahService::DISK);
    }

    /**
     * Deskriptor 128 dimensi seperti yang dihasilkan face-api.js di browser.
     *
     * @return array<int, float>
     */
    protected function embedding(float $isi = 0.12345): array
    {
        return array_fill(0, FotoReferensiWajahService::DIMENSI_EMBEDDING, $isi);
    }

    protected function foto(): UploadedFile
    {
        return UploadedFile::fake()->image('wajah.jpg', 480, 480);
    }

    #[Test]
    public function admin_dapat_mendaftarkan_foto_referensi_beserta_embedding(): void
    {
        $pegawai = Pegawai::factory()->create(['wajah_terdaftar' => false]);
        $pelaku = User::factory()->superadmin()->create();

        $this->actingAs($pelaku)
            ->post("/admin/pegawai/{$pegawai->id}/wajah", [
                'foto' => $this->foto(),
                'embedding' => $this->embedding(),
            ])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $pegawai->refresh();

        $this->assertTrue($pegawai->wajah_terdaftar);
        $this->assertNotNull($pegawai->wajah_didaftarkan_at);
        $this->assertCount(128, $pegawai->embedding_wajah);
        $this->assertSame(0.12345, $pegawai->embedding_wajah[0]);

        Storage::disk(FotoReferensiWajahService::DISK)->assertExists($pegawai->foto_referensi_path);
    }

    #[Test]
    public function foto_referensi_disimpan_di_disk_privat_bukan_publik(): void
    {
        // NFR-04: berkas foto tidak boleh dapat diakses tanpa autentikasi.
        $pegawai = Pegawai::factory()->create();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post("/admin/pegawai/{$pegawai->id}/wajah", [
                'foto' => $this->foto(),
                'embedding' => $this->embedding(),
            ]);

        $this->assertSame('local', FotoReferensiWajahService::DISK);
        $this->assertStringStartsWith('foto-referensi/', $pegawai->refresh()->foto_referensi_path);
    }

    #[Test]
    public function embedding_dengan_dimensi_salah_ditolak(): void
    {
        $pegawai = Pegawai::factory()->create();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post("/admin/pegawai/{$pegawai->id}/wajah", [
                'foto' => $this->foto(),
                'embedding' => array_fill(0, 64, 0.5),
            ])
            ->assertSessionHasErrors('embedding');

        $this->assertFalse($pegawai->refresh()->wajah_terdaftar);
    }

    #[Test]
    public function embedding_berisi_nilai_bukan_angka_ditolak(): void
    {
        $pegawai = Pegawai::factory()->create();
        $embedding = $this->embedding();
        $embedding[7] = 'bukan-angka';

        $this->actingAs(User::factory()->superadmin()->create())
            ->post("/admin/pegawai/{$pegawai->id}/wajah", [
                'foto' => $this->foto(),
                'embedding' => $embedding,
            ])
            ->assertSessionHasErrors('embedding');
    }

    #[Test]
    public function berkas_selain_gambar_ditolak(): void
    {
        $pegawai = Pegawai::factory()->create();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post("/admin/pegawai/{$pegawai->id}/wajah", [
                'foto' => UploadedFile::fake()->create('dokumen.pdf', 100, 'application/pdf'),
                'embedding' => $this->embedding(),
            ])
            ->assertSessionHasErrors('foto');
    }

    #[Test]
    public function pembaruan_mengganti_berkas_lama(): void
    {
        $pegawai = Pegawai::factory()->create();
        $admin = User::factory()->superadmin()->create();

        $this->actingAs($admin)->post("/admin/pegawai/{$pegawai->id}/wajah", [
            'foto' => $this->foto(),
            'embedding' => $this->embedding(0.1),
        ]);

        $pathLama = $pegawai->refresh()->foto_referensi_path;

        // Stempel waktu pada nama berkas beresolusi detik; majukan waktu agar
        // pembaruan benar-benar menghasilkan nama berkas berbeda.
        $this->travel(2)->seconds();

        $this->actingAs($admin)->post("/admin/pegawai/{$pegawai->id}/wajah", [
            'foto' => $this->foto(),
            'embedding' => $this->embedding(0.9),
        ]);

        $pathBaru = $pegawai->refresh()->foto_referensi_path;
        $disk = Storage::disk(FotoReferensiWajahService::DISK);

        $this->assertNotSame($pathLama, $pathBaru);
        $disk->assertMissing($pathLama);
        $disk->assertExists($pathBaru);
        $this->assertSame(0.9, $pegawai->embedding_wajah[0]);
    }

    #[Test]
    public function pencabutan_menghapus_berkas_dan_embedding(): void
    {
        $pegawai = Pegawai::factory()->create();
        $admin = User::factory()->superadmin()->create();

        $this->actingAs($admin)->post("/admin/pegawai/{$pegawai->id}/wajah", [
            'foto' => $this->foto(),
            'embedding' => $this->embedding(),
        ]);

        $path = $pegawai->refresh()->foto_referensi_path;

        $this->actingAs($admin)
            ->delete("/admin/pegawai/{$pegawai->id}/wajah")
            ->assertSessionHas('sukses');

        $pegawai->refresh();

        $this->assertFalse($pegawai->wajah_terdaftar);
        $this->assertNull($pegawai->embedding_wajah);
        $this->assertNull($pegawai->foto_referensi_path);
        $this->assertNull($pegawai->wajah_didaftarkan_at);

        Storage::disk(FotoReferensiWajahService::DISK)->assertMissing($path);

        // Pegawai tidak ikut terhapus — datanya milik WORKA (FR-PEG-02).
        $this->assertDatabaseCount('pegawai', 1);
    }

    #[Test]
    public function admin_upt_dapat_mendaftarkan_pegawai_seksi_di_bawah_unitnya(): void
    {
        $upt = UnitKerja::factory()->create(['kode' => 'BLK-SGS']);
        $seksi = UnitKerja::factory()->create(['kode' => 'BLK-SGS-TU', 'induk_id' => $upt->id]);
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $seksi->id]);

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->post("/admin/pegawai/{$pegawai->id}/wajah", [
                'foto' => $this->foto(),
                'embedding' => $this->embedding(),
            ])
            ->assertSessionHas('sukses');

        $this->assertTrue($pegawai->refresh()->wajah_terdaftar);
    }

    #[Test]
    public function admin_upt_ditolak_pada_pegawai_unit_lain(): void
    {
        $unitSendiri = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);
        $unitLain = UnitKerja::factory()->create(['kode' => 'BLK-MJK']);
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $unitLain->id]);

        $this->actingAs(User::factory()->adminUpt($unitSendiri)->create())
            ->post("/admin/pegawai/{$pegawai->id}/wajah", [
                'foto' => $this->foto(),
                'embedding' => $this->embedding(),
            ])
            ->assertForbidden();

        $this->assertFalse($pegawai->refresh()->wajah_terdaftar);
    }

    /**
     * Daftarkan wajah tanpa melalui HTTP, agar test tamu benar-benar berangkat
     * tanpa sesi terautentikasi.
     */
    protected function daftarkanLangsung(Pegawai $pegawai): void
    {
        app(FotoReferensiWajahService::class)->daftarkan(
            $pegawai,
            $this->foto(),
            $this->embedding(),
            User::factory()->superadmin()->create(),
        );
    }

    #[Test]
    public function tamu_tidak_dapat_mengambil_foto_referensi(): void
    {
        // NFR-04: akses berkas foto tanpa autentikasi tidak diperbolehkan.
        $pegawai = Pegawai::factory()->create();
        $this->daftarkanLangsung($pegawai);

        $this->get("/admin/pegawai/{$pegawai->id}/wajah")->assertRedirect('/masuk');
    }

    #[Test]
    public function admin_dapat_mengambil_foto_referensi(): void
    {
        $pegawai = Pegawai::factory()->create();
        $this->daftarkanLangsung($pegawai);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get("/admin/pegawai/{$pegawai->id}/wajah")
            ->assertOk();
    }

    #[Test]
    public function admin_upt_tidak_dapat_mengambil_foto_pegawai_unit_lain(): void
    {
        $unitSendiri = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);
        $unitLain = UnitKerja::factory()->create(['kode' => 'BLK-MJK']);
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $unitLain->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post("/admin/pegawai/{$pegawai->id}/wajah", [
                'foto' => $this->foto(),
                'embedding' => $this->embedding(),
            ]);

        $this->actingAs(User::factory()->adminUpt($unitSendiri)->create())
            ->get("/admin/pegawai/{$pegawai->id}/wajah")
            ->assertForbidden();
    }

    #[Test]
    public function pegawai_tanpa_foto_menjawab_404(): void
    {
        $pegawai = Pegawai::factory()->create(['wajah_terdaftar' => false]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get("/admin/pegawai/{$pegawai->id}/wajah")
            ->assertNotFound();
    }

    #[Test]
    public function pendaftaran_wajah_tercatat_pada_audit_trail(): void
    {
        $pegawai = Pegawai::factory()->create(['nip' => '198512312010011001']);
        $pelaku = User::factory()->superadmin()->create();

        $this->actingAs($pelaku)->post("/admin/pegawai/{$pegawai->id}/wajah", [
            'foto' => $this->foto(),
            'embedding' => $this->embedding(),
        ]);

        $log = LogAktivitas::aksi(AksiLog::Ubah)->sole();

        $this->assertSame($pelaku->id, $log->user_id);
        $this->assertTrue($log->subjek->is($pegawai));
        $this->assertStringContainsString('198512312010011001', $log->deskripsi);
    }
}
