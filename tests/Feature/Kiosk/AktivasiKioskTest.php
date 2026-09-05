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
 * Aktivasi perangkat kiosk dan penerbitan device_token (FR-AUTH-01, FR-USR-03, NFR-03).
 */
class AktivasiKioskTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function layar_aktivasi_tampil_untuk_perangkat_yang_belum_aktif(): void
    {
        $this->get('/kiosk/aktivasi')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Kiosk/Aktivasi'));
    }

    #[Test]
    public function kode_yang_sah_menerbitkan_token_dan_mencatat_ip(): void
    {
        $kiosk = $this->kioskMenungguAktivasi();

        $respons = $this->post('/kiosk/aktivasi', ['kode_aktivasi' => 'ABCD2345']);

        $respons->assertRedirect('/');
        $respons->assertCookie(KioskService::NAMA_COOKIE);

        $kiosk->refresh();

        $this->assertNotNull($kiosk->device_token);
        $this->assertNull($kiosk->kode_aktivasi, 'Kode aktivasi harus hangus setelah dipakai.');
        $this->assertSame(StatusKiosk::Online, $kiosk->status);
        $this->assertSame('127.0.0.1', $kiosk->ip_terakhir);
        $this->assertNotNull($kiosk->diaktifkan_pada);
        $this->assertNotNull($kiosk->login_terakhir_at);
    }

    #[Test]
    public function device_token_disimpan_sebagai_hash_bukan_token_mentah(): void
    {
        $this->kioskMenungguAktivasi();

        $respons = $this->post('/kiosk/aktivasi', ['kode_aktivasi' => 'ABCD2345']);

        $tokenMentah = $respons->getCookie(KioskService::NAMA_COOKIE)->getValue();
        $tersimpan = Kiosk::sole()->device_token;

        $this->assertNotSame($tokenMentah, $tersimpan);
        $this->assertSame(KioskService::hashToken($tokenMentah), $tersimpan);
    }

    #[Test]
    public function kode_aktivasi_hanya_berlaku_sekali(): void
    {
        $this->kioskMenungguAktivasi();

        $this->post('/kiosk/aktivasi', ['kode_aktivasi' => 'ABCD2345'])->assertRedirect('/');

        $this->post('/kiosk/aktivasi', ['kode_aktivasi' => 'ABCD2345'])
            ->assertSessionHasErrors('kode_aktivasi');
    }

    #[Test]
    public function kode_kedaluwarsa_ditolak(): void
    {
        Kiosk::factory()->kodeKedaluwarsa('ABCD2345')->create([
            'unit_kerja_id' => UnitKerja::factory(),
        ]);

        $this->post('/kiosk/aktivasi', ['kode_aktivasi' => 'ABCD2345'])
            ->assertSessionHasErrors('kode_aktivasi');

        $this->assertNull(Kiosk::sole()->device_token);
    }

    #[Test]
    public function kode_milik_kiosk_nonaktif_ditolak(): void
    {
        Kiosk::factory()->menungguAktivasi('ABCD2345')->nonaktif()->create([
            'unit_kerja_id' => UnitKerja::factory(),
        ]);

        $this->post('/kiosk/aktivasi', ['kode_aktivasi' => 'ABCD2345'])
            ->assertSessionHasErrors('kode_aktivasi');
    }

    #[Test]
    public function kode_diterima_apa_pun_format_tanda_hubung_dan_hurufnya(): void
    {
        $this->kioskMenungguAktivasi();

        $this->post('/kiosk/aktivasi', ['kode_aktivasi' => 'abcd-2345'])
            ->assertRedirect('/');

        $this->assertNotNull(Kiosk::sole()->device_token);
    }

    #[Test]
    public function aktivasi_tercatat_pada_audit_trail(): void
    {
        $kiosk = $this->kioskMenungguAktivasi();

        $this->post('/kiosk/aktivasi', ['kode_aktivasi' => 'ABCD2345']);

        $log = LogAktivitas::aksi(AksiLog::AktivasiKiosk)->sole();

        $this->assertSame($kiosk->id, $log->kiosk_id);
        $this->assertStringContainsString($kiosk->nama_titik, $log->deskripsi);
        $this->assertStringContainsString('127.0.0.1', $log->deskripsi);
    }

    #[Test]
    public function kode_salah_tercatat_sebagai_percobaan_gagal(): void
    {
        $this->kioskMenungguAktivasi();

        $this->post('/kiosk/aktivasi', ['kode_aktivasi' => 'ZZZZ9999']);

        $this->assertSame(1, LogAktivitas::aksi(AksiLog::AktivasiKioskGagal)->count());
    }

    protected function kioskMenungguAktivasi(): Kiosk
    {
        return Kiosk::factory()->menungguAktivasi('ABCD2345')->create([
            'nama_titik' => 'Aula Senam BLK Surabaya',
            'unit_kerja_id' => UnitKerja::factory()->create(['kode' => 'BLK-SBY']),
        ]);
    }
}
