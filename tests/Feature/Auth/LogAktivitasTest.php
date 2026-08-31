<?php

namespace Tests\Feature\Auth;

use App\Enums\AksiLog;
use App\Models\LogAktivitas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audit trail login/logout: siapa, kapan, aksi apa (FR-AUTH-03).
 */
class LogAktivitasTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function masuk_yang_berhasil_tercatat_beserta_pelaku_dan_ip(): void
    {
        $pengguna = User::factory()->create([
            'nama' => 'Admin UPT BLK Surabaya',
            'email' => 'admin.blksby@capture.test',
            'password' => Hash::make('rahasia123'),
        ]);

        $this->post('/masuk', [
            'email' => 'admin.blksby@capture.test',
            'password' => 'rahasia123',
        ]);

        $log = LogAktivitas::aksi(AksiLog::Masuk)->sole();

        $this->assertSame($pengguna->id, $log->user_id);
        $this->assertStringContainsString('Admin UPT BLK Surabaya', $log->deskripsi);
        $this->assertNotNull($log->ip_address);
        $this->assertNotNull($log->created_at);
    }

    #[Test]
    public function keluar_tercatat(): void
    {
        $pengguna = User::factory()->create(['nama' => 'Superadmin SI-ABSEN']);

        $this->actingAs($pengguna)->post('/keluar');

        $log = LogAktivitas::aksi(AksiLog::Keluar)->sole();

        $this->assertSame($pengguna->id, $log->user_id);
        $this->assertStringContainsString('Superadmin SI-ABSEN', $log->deskripsi);
    }

    #[Test]
    public function percobaan_masuk_gagal_tercatat_tanpa_membocorkan_kata_sandi(): void
    {
        User::factory()->create([
            'email' => 'admin.dinas@capture.test',
            'password' => Hash::make('rahasia123'),
        ]);

        $this->post('/masuk', [
            'email' => 'admin.dinas@capture.test',
            'password' => 'KataSandiSalah123',
        ]);

        $log = LogAktivitas::aksi(AksiLog::MasukGagal)->sole();

        $this->assertStringContainsString('admin.dinas@capture.test', $log->deskripsi);
        $this->assertStringNotContainsString('KataSandiSalah123', $log->deskripsi);
        $this->assertDatabaseMissing('log_aktivitas', ['deskripsi' => 'KataSandiSalah123']);
    }

    #[Test]
    public function penolakan_akun_nonaktif_tercatat_sebagai_percobaan_gagal(): void
    {
        User::factory()->nonaktif()->create([
            'email' => 'mantan.admin@capture.test',
            'password' => Hash::make('rahasia123'),
        ]);

        $this->post('/masuk', [
            'email' => 'mantan.admin@capture.test',
            'password' => 'rahasia123',
        ]);

        $this->assertSame(1, LogAktivitas::aksi(AksiLog::MasukGagal)->count());
        $this->assertSame(0, LogAktivitas::aksi(AksiLog::Masuk)->count());
    }

    #[Test]
    public function log_bersifat_append_only_tanpa_kolom_updated_at(): void
    {
        $this->actingAs(User::factory()->create())->post('/keluar');

        $log = LogAktivitas::terbaru()->first();

        $this->assertNull($log->updated_at);
        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());
    }

    #[Test]
    public function log_dapat_menunjuk_subjek_perubahan_data(): void
    {
        $pelaku = User::factory()->superadmin()->create();
        $sasaran = User::factory()->create(['nama' => 'Admin UPT Baru']);

        $log = app(\App\Services\LogAktivitasService::class)->catat(
            AksiLog::Buat,
            'Membuat akun Admin UPT Baru.',
            user: $pelaku,
            subjek: $sasaran,
        );

        $this->assertSame($pelaku->id, $log->user_id);
        $this->assertTrue($log->subjek->is($sasaran));
    }
}
