<?php

namespace Tests\Feature\Admin;

use App\Enums\AksiLog;
use App\Models\LogAktivitas;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\KartuRfidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pendaftaran kartu RFID pegawai (FR-TAP-03).
 */
class KartuRfidTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_dapat_mendaftarkan_kartu(): void
    {
        $pegawai = Pegawai::factory()->create(['nama' => 'Ahmad Fauzi']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post("/admin/pegawai/{$pegawai->id}/kartu", ['uid_kartu' => '04:A3:B2:1C'])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $pegawai->refresh();

        // Pemisah dibuang dan huruf disamakan agar reader bermerek lain
        // menghasilkan nilai yang sama untuk kartu yang sama.
        $this->assertSame('04A3B21C', $pegawai->uid_kartu);
        $this->assertNotNull($pegawai->kartu_didaftarkan_at);
    }

    #[Test]
    public function satu_kartu_tidak_boleh_dipakai_dua_pegawai(): void
    {
        Pegawai::factory()->create(['uid_kartu' => '04A3B21C']);
        $lain = Pegawai::factory()->create();

        // Tap kartu ganda akan salah alamat, jadi ditolak di hulu.
        $this->actingAs(User::factory()->superadmin()->create())
            ->post("/admin/pegawai/{$lain->id}/kartu", ['uid_kartu' => '04-a3-b2-1c'])
            ->assertSessionHasErrors('uid_kartu');

        $this->assertNull($lain->refresh()->uid_kartu);
    }

    #[Test]
    public function pegawai_dapat_mengganti_kartunya_sendiri(): void
    {
        $pegawai = Pegawai::factory()->create(['uid_kartu' => '04A3B21C']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post("/admin/pegawai/{$pegawai->id}/kartu", ['uid_kartu' => '11223344'])
            ->assertSessionHas('sukses');

        $this->assertSame('11223344', $pegawai->refresh()->uid_kartu);
    }

    #[Test]
    public function kartu_dapat_dicabut_tanpa_menghapus_pegawai(): void
    {
        $pegawai = Pegawai::factory()->create(['uid_kartu' => '04A3B21C']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->delete("/admin/pegawai/{$pegawai->id}/kartu")
            ->assertSessionHas('sukses');

        $pegawai->refresh();

        $this->assertNull($pegawai->uid_kartu);
        $this->assertNull($pegawai->kartu_didaftarkan_at);
        $this->assertDatabaseCount('pegawai', 1);
    }

    #[Test]
    public function admin_upt_dapat_mendaftarkan_kartu_pegawai_seksi_di_bawahnya(): void
    {
        $upt = UnitKerja::factory()->create(['kode' => 'BLK-SGS']);
        $seksi = UnitKerja::factory()->create(['kode' => 'BLK-SGS-TU', 'induk_id' => $upt->id]);
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $seksi->id]);

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->post("/admin/pegawai/{$pegawai->id}/kartu", ['uid_kartu' => '04A3B21C'])
            ->assertSessionHas('sukses');
    }

    #[Test]
    public function admin_upt_ditolak_pada_pegawai_unit_lain(): void
    {
        $unitSendiri = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);
        $unitLain = UnitKerja::factory()->create(['kode' => 'BLK-MJK']);
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $unitLain->id]);

        $this->actingAs(User::factory()->adminUpt($unitSendiri)->create())
            ->post("/admin/pegawai/{$pegawai->id}/kartu", ['uid_kartu' => '04A3B21C'])
            ->assertForbidden();
    }

    #[Test]
    public function uid_terlalu_pendek_ditolak(): void
    {
        $pegawai = Pegawai::factory()->create();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post("/admin/pegawai/{$pegawai->id}/kartu", ['uid_kartu' => '12'])
            ->assertSessionHasErrors('uid_kartu');
    }

    #[Test]
    public function pendaftaran_kartu_tercatat_pada_audit_trail(): void
    {
        $pegawai = Pegawai::factory()->create(['nip' => '198512312010011001']);
        $pelaku = User::factory()->superadmin()->create();

        $this->actingAs($pelaku)->post("/admin/pegawai/{$pegawai->id}/kartu", ['uid_kartu' => '04A3B21C']);

        $log = LogAktivitas::aksi(AksiLog::Ubah)->sole();

        $this->assertSame($pelaku->id, $log->user_id);
        $this->assertTrue($log->subjek->is($pegawai));
        $this->assertStringContainsString('198512312010011001', $log->deskripsi);

        // UID tidak ikut tercatat: kartu adalah kredensial fisik.
        $this->assertStringNotContainsString('04A3B21C', $log->deskripsi);
    }

    #[Test]
    public function normalisasi_menyamakan_gaya_penulisan_reader(): void
    {
        foreach (['04:a3:b2:1c', '04-A3-B2-1C', '04 a3 b2 1c', '04a3b21c'] as $bentuk) {
            $this->assertSame('04A3B21C', KartuRfidService::normalkan($bentuk));
        }
    }
}
