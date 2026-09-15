<?php

namespace Tests\Feature\Admin;

use App\Enums\AksiLog;
use App\Enums\StatusRiwayatLaporan;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\LogAktivitas;
use App\Models\Pegawai;
use App\Models\RiwayatBackup;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Maintenance & Backup — arsip data absensi (FR-MTN-01). Superadmin saja.
 */
class MaintenanceBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function buatRiwayat(array $atribut = []): RiwayatBackup
    {
        return RiwayatBackup::query()->create(array_merge([
            'dipicu_oleh' => 'manual',
            'user_id' => null,
            'status' => StatusRiwayatLaporan::Selesai,
            'nama_berkas' => 'backup-absensi-uji.zip',
            'path' => null,
        ], $atribut));
    }

    #[Test]
    public function admin_dinas_ditolak_membuka_halaman(): void
    {
        // Superadmin-murni, beda dari Setting Absen/Integrasi WORKA yang
        // masih boleh diakses admin dinas.
        $this->actingAs(User::factory()->adminDinas()->create())
            ->get('/admin/setting/maintenance')
            ->assertForbidden();
    }

    #[Test]
    public function admin_upt_ditolak_membuka_halaman(): void
    {
        $unit = UnitKerja::factory()->create();

        $this->actingAs(User::factory()->adminUpt($unit)->create())
            ->get('/admin/setting/maintenance')
            ->assertForbidden();
    }

    #[Test]
    public function superadmin_dapat_membuka_halaman(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/admin/setting/maintenance')
            ->assertOk();
    }

    #[Test]
    public function backup_manual_mengantre_lalu_selesai_berisi_tabel_yang_diharapkan(): void
    {
        Storage::fake(BackupService::DISK);

        $unit = UnitKerja::factory()->create();
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $unit->id]);
        $event = EventAbsen::factory()->create();
        Absensi::factory()->create(['event_absen_id' => $event->id, 'pegawai_id' => $pegawai->id]);

        $superadmin = User::factory()->superadmin()->create();

        // Terminate() dipanggil sinkron dalam pengujian (lihat catatan yang
        // sama di LaporanTest) — job afterResponse() benar-benar berjalan.
        $this->actingAs($superadmin)
            ->post('/admin/setting/maintenance/backup')
            ->assertRedirect();

        $riwayat = RiwayatBackup::query()->sole();
        $this->assertSame('selesai', $riwayat->status->value);
        $this->assertSame('manual', $riwayat->dipicu_oleh);
        $this->assertSame($superadmin->id, $riwayat->user_id);
        $this->assertNotNull($riwayat->ukuran_bytes);

        $isi = Storage::disk(BackupService::DISK)->get($riwayat->path);
        $sementara = tempnam(sys_get_temp_dir(), 'backup-uji').'.zip';
        file_put_contents($sementara, $isi);

        $zip = new ZipArchive;
        $zip->open($sementara);
        $namaBerkas = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $namaBerkas[] = $zip->getNameIndex($i);
        }
        $zip->close();
        unlink($sementara);

        $this->assertEqualsCanonicalizing(
            ['unit_kerja.json', 'pegawai.json', 'kiosk.json', 'event_absen.json', 'event_unit_kerja.json', 'absensi.json'],
            $namaBerkas,
        );
    }

    #[Test]
    public function backup_terjadwal_tercatat_tanpa_user(): void
    {
        Storage::fake(BackupService::DISK);

        app(BackupService::class)->buatTerjadwal();

        $riwayat = RiwayatBackup::query()->sole();
        $this->assertSame('terjadwal', $riwayat->dipicu_oleh);
        $this->assertNull($riwayat->user_id);
        $this->assertSame('selesai', $riwayat->status->value);
    }

    #[Test]
    public function perintah_artisan_menjalankan_backup_dan_retensi(): void
    {
        Storage::fake(BackupService::DISK);

        $this->artisan('absensi:backup')->assertSuccessful();

        $this->assertDatabaseCount('riwayat_backup', 1);
        $this->assertDatabaseHas('riwayat_backup', ['dipicu_oleh' => 'terjadwal', 'status' => 'selesai']);
    }

    #[Test]
    public function retensi_menghapus_backup_yang_melampaui_batas(): void
    {
        Storage::fake(BackupService::DISK);
        Storage::disk(BackupService::DISK)->put('backup/lama.zip', 'isi');

        $lama = $this->buatRiwayat([
            'path' => 'backup/lama.zip',
            'selesai_pada' => now()->subDays(40),
            'created_at' => now()->subDays(40),
        ]);
        $baru = $this->buatRiwayat(['selesai_pada' => now()->subDays(5), 'created_at' => now()->subDays(5)]);

        app(BackupService::class)->simpanRetensi(30);
        $dihapus = app(BackupService::class)->bersihkanKedaluwarsa();

        $this->assertSame(1, $dihapus);
        $this->assertDatabaseMissing('riwayat_backup', ['id' => $lama->id]);
        $this->assertDatabaseHas('riwayat_backup', ['id' => $baru->id]);
        Storage::disk(BackupService::DISK)->assertMissing('backup/lama.zip');
    }

    #[Test]
    public function retensi_di_luar_rentang_ditolak(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/admin/setting/maintenance', ['retensi_hari' => 1000])
            ->assertSessionHasErrors('retensi_hari');
    }

    #[Test]
    public function unduh_menolak_yang_belum_selesai(): void
    {
        Storage::fake(BackupService::DISK);

        $riwayat = $this->buatRiwayat(['status' => StatusRiwayatLaporan::Diproses, 'path' => null]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get("/admin/setting/maintenance/backup/{$riwayat->id}/unduh")
            ->assertNotFound();
    }

    #[Test]
    public function unduh_mengembalikan_berkas_yang_sudah_selesai(): void
    {
        Storage::fake(BackupService::DISK);
        Storage::disk(BackupService::DISK)->put('backup/x.zip', 'isi-zip');

        $riwayat = $this->buatRiwayat(['path' => 'backup/x.zip']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get("/admin/setting/maintenance/backup/{$riwayat->id}/unduh")
            ->assertOk()
            ->assertDownload($riwayat->nama_berkas);
    }

    #[Test]
    public function hapus_menghapus_baris_beserta_berkasnya(): void
    {
        Storage::fake(BackupService::DISK);
        Storage::disk(BackupService::DISK)->put('backup/x.zip', 'isi-zip');

        $riwayat = $this->buatRiwayat(['path' => 'backup/x.zip']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->delete("/admin/setting/maintenance/backup/{$riwayat->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('riwayat_backup', ['id' => $riwayat->id]);
        Storage::disk(BackupService::DISK)->assertMissing('backup/x.zip');
    }

    #[Test]
    public function admin_dinas_ditolak_membuat_backup_maupun_menghapus(): void
    {
        $adminDinas = User::factory()->adminDinas()->create();
        $riwayat = $this->buatRiwayat();

        $this->actingAs($adminDinas)->post('/admin/setting/maintenance/backup')->assertForbidden();
        $this->actingAs($adminDinas)->delete("/admin/setting/maintenance/backup/{$riwayat->id}")->assertForbidden();
    }

    /* ---------------------------------------------------------------------
     * Pulihkan (restore) — FR-MTN-02, menggabungkan bukan mengganti total.
     * ------------------------------------------------------------------- */

    #[Test]
    public function pulihkan_mengembalikan_baris_yang_terhapus_setelah_backup(): void
    {
        Storage::fake(BackupService::DISK);

        $unit = UnitKerja::factory()->create();
        $pegawai = Pegawai::factory()->create(['nip' => '199001012020011001', 'nama' => 'Ahmad Fauzi', 'unit_kerja_id' => $unit->id]);
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($unit);
        $absensi = Absensi::factory()->create(['event_absen_id' => $event->id, 'pegawai_id' => $pegawai->id]);

        $riwayat = app(BackupService::class)->buatTerjadwal();

        // Baris "terhapus keliru" SETELAH backup dibuat — persis skenario
        // yang jadi alasan fitur ini ada.
        $absensi->delete();
        $this->assertDatabaseMissing('absensi', ['id' => $absensi->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post("/admin/setting/maintenance/backup/{$riwayat->id}/pulihkan", [
                'konfirmasi' => $riwayat->nama_berkas,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('absensi', ['id' => $absensi->id, 'pegawai_id' => $pegawai->id]);
    }

    #[Test]
    public function pulihkan_tidak_menghapus_data_yang_dibuat_setelah_backup(): void
    {
        // Ini yang membedakan "gabung" dari "ganti total" — jaminan yang
        // paling penting dari seluruh fitur ini.
        Storage::fake(BackupService::DISK);

        $unit = UnitKerja::factory()->create();
        Pegawai::factory()->create(['unit_kerja_id' => $unit->id]);

        $riwayat = app(BackupService::class)->buatTerjadwal();

        $pegawaiBaru = Pegawai::factory()->create(['unit_kerja_id' => $unit->id, 'nama' => 'Baru Setelah Backup']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post("/admin/setting/maintenance/backup/{$riwayat->id}/pulihkan", [
                'konfirmasi' => $riwayat->nama_berkas,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('pegawai', ['id' => $pegawaiBaru->id]);
    }

    #[Test]
    public function pulihkan_menegakkan_hierarki_unit_kerja_dan_pivot_event(): void
    {
        // unit_kerja.induk_id menaut ke dirinya sendiri — kasus yang
        // menuntut dua tahap (lihat BackupService::KOSONGKAN_DULU). Pivot
        // event_unit_kerja tidak punya model Eloquent sama sekali.
        Storage::fake(BackupService::DISK);

        $induk = UnitKerja::factory()->create();
        $anak = UnitKerja::factory()->create(['induk_id' => $induk->id]);
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($anak);

        $riwayat = app(BackupService::class)->buatTerjadwal();

        UnitKerja::whereKey($anak->id)->update(['induk_id' => null]);
        DB::table('event_unit_kerja')->where('event_absen_id', $event->id)->delete();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post("/admin/setting/maintenance/backup/{$riwayat->id}/pulihkan", [
                'konfirmasi' => $riwayat->nama_berkas,
            ])
            ->assertRedirect();

        $this->assertSame($induk->id, UnitKerja::query()->find($anak->id)->induk_id);
        $this->assertDatabaseHas('event_unit_kerja', ['event_absen_id' => $event->id, 'unit_kerja_id' => $anak->id]);
    }

    #[Test]
    public function pulihkan_tercatat_pada_audit_trail(): void
    {
        Storage::fake(BackupService::DISK);

        $riwayat = app(BackupService::class)->buatTerjadwal();
        $superadmin = User::factory()->superadmin()->create(['nama' => 'Budi Superadmin']);

        $this->actingAs($superadmin)->post("/admin/setting/maintenance/backup/{$riwayat->id}/pulihkan", [
            'konfirmasi' => $riwayat->nama_berkas,
        ]);

        $log = LogAktivitas::query()->where('aksi', AksiLog::PulihkanBackup)->latest()->first();

        $this->assertNotNull($log);
        $this->assertSame($superadmin->id, $log->user_id);
        $this->assertStringContainsString($riwayat->nama_berkas, $log->deskripsi);
    }

    #[Test]
    public function pulihkan_menolak_konfirmasi_yang_tidak_cocok(): void
    {
        Storage::fake(BackupService::DISK);

        $riwayat = app(BackupService::class)->buatTerjadwal();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post("/admin/setting/maintenance/backup/{$riwayat->id}/pulihkan", [
                'konfirmasi' => 'nama-berkas-yang-salah.zip',
            ])
            ->assertSessionHasErrors('konfirmasi');
    }

    #[Test]
    public function pulihkan_menolak_backup_yang_belum_selesai(): void
    {
        $riwayat = $this->buatRiwayat(['status' => StatusRiwayatLaporan::Diproses, 'path' => null]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post("/admin/setting/maintenance/backup/{$riwayat->id}/pulihkan", [
                'konfirmasi' => $riwayat->nama_berkas,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function admin_dinas_ditolak_memulihkan(): void
    {
        Storage::fake(BackupService::DISK);

        $riwayat = app(BackupService::class)->buatTerjadwal();

        $this->actingAs(User::factory()->adminDinas()->create())
            ->post("/admin/setting/maintenance/backup/{$riwayat->id}/pulihkan", [
                'konfirmasi' => $riwayat->nama_berkas,
            ])
            ->assertForbidden();
    }
}
