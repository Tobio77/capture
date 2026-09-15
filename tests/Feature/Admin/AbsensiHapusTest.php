<?php

namespace Tests\Feature\Admin;

use App\Enums\AksiLog;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\LogAktivitas;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\AbsensiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Hapus satu baris absensi — superadmin saja, tercatat pada audit trail.
 *
 * Fitur ini BUKAN jalur normal: dipakai untuk keperluan pengujian atau
 * membetulkan tap yang keliru tercatat, bukan operasional sehari-hari —
 * karena itu sengaja dikunci lebih ketat daripada aksi hapus lain di
 * aplikasi ini (superadmin murni, bukan superadmin+admin_dinas).
 */
class AbsensiHapusTest extends TestCase
{
    use RefreshDatabase;

    protected function absensi(array $atribut = []): Absensi
    {
        $unit = UnitKerja::factory()->create();
        $event = EventAbsen::factory()->create(['nama' => 'Apel Pagi']);
        $pegawai = Pegawai::factory()->create(['nip' => '199001012020011001', 'nama' => 'Ahmad Fauzi', 'unit_kerja_id' => $unit->id]);

        return Absensi::factory()->create(array_merge([
            'event_absen_id' => $event->id,
            'pegawai_id' => $pegawai->id,
        ], $atribut));
    }

    #[Test]
    public function superadmin_dapat_menghapus_absensi(): void
    {
        $absensi = $this->absensi();

        $this->actingAs(User::factory()->superadmin()->create())
            ->delete("/admin/absensi/{$absensi->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('absensi', ['id' => $absensi->id]);
    }

    #[Test]
    public function menghapus_absensi_tercatat_pada_audit_trail(): void
    {
        $absensi = $this->absensi();
        $superadmin = User::factory()->superadmin()->create(['nama' => 'Budi Superadmin']);

        $this->actingAs($superadmin)->delete("/admin/absensi/{$absensi->id}");

        $log = LogAktivitas::query()->where('aksi', AksiLog::Hapus)->latest()->first();

        $this->assertNotNull($log);
        $this->assertSame($superadmin->id, $log->user_id);
        $this->assertStringContainsString('199001012020011001', $log->deskripsi);
        $this->assertStringContainsString('Ahmad Fauzi', $log->deskripsi);
        $this->assertStringContainsString('Datang', $log->deskripsi);
    }

    #[Test]
    public function menghapus_absensi_menghapus_foto_dari_disk(): void
    {
        Storage::fake(AbsensiService::DISK);
        Storage::disk(AbsensiService::DISK)->put('foto-absen/uji.jpg', 'isi-foto');

        $absensi = $this->absensi(['foto_path' => 'foto-absen/uji.jpg']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->delete("/admin/absensi/{$absensi->id}");

        Storage::disk(AbsensiService::DISK)->assertMissing('foto-absen/uji.jpg');
    }

    #[Test]
    public function absensi_tanpa_foto_terhapus_tanpa_error(): void
    {
        Storage::fake(AbsensiService::DISK);

        $absensi = $this->absensi(['foto_path' => null]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->delete("/admin/absensi/{$absensi->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('absensi', ['id' => $absensi->id]);
    }

    #[Test]
    public function admin_dinas_ditolak(): void
    {
        $absensi = $this->absensi();

        $this->actingAs(User::factory()->adminDinas()->create())
            ->delete("/admin/absensi/{$absensi->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('absensi', ['id' => $absensi->id]);
    }

    #[Test]
    public function admin_upt_ditolak(): void
    {
        $unit = UnitKerja::factory()->create();
        $absensi = $this->absensi();

        $this->actingAs(User::factory()->adminUpt($unit)->create())
            ->delete("/admin/absensi/{$absensi->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('absensi', ['id' => $absensi->id]);
    }
}
