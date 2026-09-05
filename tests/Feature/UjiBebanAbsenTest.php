<?php

namespace Tests\Feature;

use App\Exceptions\AbsenGandaException;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\AbsensiService;
use App\Services\KioskService;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Perilaku sistem ketika beberapa perangkat absen melayani satu event
 * bersamaan (S27).
 *
 * Dua hal yang benar-benar terjadi di lapangan diuji di sini. Pertama, dua
 * perangkat yang melayani orang yang sama dalam hitungan milidetik — pegawai
 * yang mengira tapnya tidak terbaca lalu mengulang di perangkat sebelah.
 * Kedua, beberapa perangkat yang berbagi satu alamat IP kantor: batas laju
 * tidak boleh membuat mereka saling menghabiskan kuota.
 *
 * Uji beban sesungguhnya — waktu tanggap terhadap NFR-01 — dijalankan lewat
 * perintah `php artisan absen:uji-beban`, yang menembak HTTP sungguhan.
 */
class UjiBebanAbsenTest extends TestCase
{
    use RefreshDatabase;

    protected UnitKerja $unitKerja;

    protected EventAbsen $event;

    protected Pegawai $pegawai;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AbsensiService::DISK);

        $this->unitKerja = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);

        $this->event = EventAbsen::factory()->create([
            'tanggal' => '2026-09-07',
            'jam_mulai' => '07:30',
            'toleransi_menit' => 15,
        ]);
        $this->event->unitKerja()->attach($this->unitKerja);

        $this->pegawai = Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        app(SettingAbsenService::class)->simpan(
            ['metode_wajah_aktif' => false],
            User::factory()->superadmin()->create(),
        );
    }

    /* ---------------------------------------------------------------------
     * Tap bersamaan pada pegawai yang sama.
     * ------------------------------------------------------------------- */

    #[Test]
    public function tap_bersamaan_pada_pegawai_yang_sama_menghasilkan_satu_baris(): void
    {
        /*
         * Balapan sungguhan melibatkan dua koneksi basis data dan tidak dapat
         * ditirukan dalam satu proses PHPUnit — savepoint milik satu proses
         * justru membatalkan baris yang sengaja disisipkan untuk menirunya.
         * Yang diuji di sini adalah invariannya: berapa pun tap yang masuk
         * untuk pasangan (event, pegawai, jenis) yang sama, hasilnya satu
         * baris. Balapan lintas koneksi dibuktikan perintah
         * `php artisan absen:uji-beban --rebutan`, yang menembak HTTP paralel
         * sungguhan; wasit terakhirnya adalah kunci unik di basis data, yang
         * ditegakkan migration dan ditangani `updateOrCreate` lewat
         * createOrFirst.
         */
        $absensi = app(AbsensiService::class);

        $absensi->catat($this->event, $this->pegawai, null, [
            'jenis' => 'datang',
            'metode' => 'manual',
        ]);

        // Tap kedua ditolak, bukan menimpa (FR-TAP-05 revisi S28a).
        foreach (range(1, 4) as $ke) {
            try {
                $absensi->catat($this->event, $this->pegawai, null, [
                    'jenis' => 'datang',
                    'metode' => 'manual',
                ]);

                $this->fail('Tap kedua seharusnya ditolak.');
            } catch (AbsenGandaException $ganda) {
                $this->assertSame('Sudah absen datang pukul '.
                    $ganda->tercatat->waktu->format('H:i').'.', $ganda->pesan());
            }
        }

        $this->assertSame(1, Absensi::query()->count());
    }

    #[Test]
    public function tap_dari_perangkat_kedua_ditolak_dan_tidak_memindahkan_kepemilikan(): void
    {
        $perangkatA = Kiosk::factory()->diaktifkan('token-a')->create([
            'unit_kerja_id' => $this->unitKerja->id,
        ]);
        $perangkatB = Kiosk::factory()->diaktifkan('token-b')->create([
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        $absensi = app(AbsensiService::class);

        $absensi->catat($this->event, $this->pegawai, $perangkatA, [
            'jenis' => 'datang',
            'metode' => 'rfid',
        ]);

        $this->expectException(AbsenGandaException::class);

        // Perangkat kedua menolak, dan perangkat pertama tetap tercatat sebagai
        // pemilik tap — bukti tidak berpindah tangan hanya karena tap ulang.
        try {
            $absensi->catat($this->event, $this->pegawai, $perangkatB, [
                'jenis' => 'datang',
                'metode' => 'rfid',
            ]);
        } finally {
            $this->assertSame(1, Absensi::query()->count());
            $this->assertSame($perangkatA->id, Absensi::query()->value('kiosk_id'));
        }
    }

    /* ---------------------------------------------------------------------
     * Batas laju per perangkat, bukan per alamat IP.
     * ------------------------------------------------------------------- */

    #[Test]
    public function batas_laju_dihitung_per_perangkat_bukan_per_alamat_ip(): void
    {
        /*
         * Beberapa titik absen di satu kantor keluar lewat satu NAT. Bila
         * batasnya dikunci pada IP, kuota satu perangkat dibagi rata dan
         * perangkat kesepuluh mulai menerima 429 tanpa melakukan apa pun yang
         * berlebihan — padahal penarikan daftar presensi tiap 10 detik hanya
         * enam permintaan per menit.
         */
        Kiosk::factory()->diaktifkan('token-a')->create(['unit_kerja_id' => $this->unitKerja->id]);
        Kiosk::factory()->diaktifkan('token-b')->create(['unit_kerja_id' => $this->unitKerja->id]);

        $batas = 60;

        foreach (range(1, $batas) as $ke) {
            $this->tarikPresensi('token-a')->assertOk();
        }

        // Perangkat A sudah menghabiskan kuotanya sendiri.
        $this->tarikPresensi('token-a')->assertStatus(429);

        // Perangkat B, dari alamat IP yang sama, tetap dilayani.
        $this->tarikPresensi('token-b')->assertOk();
    }

    #[Test]
    public function batas_laju_tap_juga_terpisah_antar_perangkat(): void
    {
        $a = Kiosk::factory()->diaktifkan('token-a')->create(['unit_kerja_id' => $this->unitKerja->id]);
        $b = Kiosk::factory()->diaktifkan('token-b')->create(['unit_kerja_id' => $this->unitKerja->id]);

        // Keduanya melayani event yang sama — dua meja registrasi pada satu
        // kegiatan, keadaan yang justru paling sering memicu batas laju.
        $this->gabungkanKeEvent($this->event, $a);
        $this->gabungkanKeEvent($this->event, $b);

        foreach (range(1, 120) as $ke) {
            $this->withCookie(KioskService::NAMA_COOKIE, 'token-a')
                ->post('/kiosk/event/tap/identifikasi', ['id_card' => $this->pegawai->nip], [
                    'Accept' => 'application/json',
                ])
                ->assertOk();
        }

        $this->withCookie(KioskService::NAMA_COOKIE, 'token-a')
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => $this->pegawai->nip], [
                'Accept' => 'application/json',
            ])
            ->assertStatus(429);

        $this->withCookie(KioskService::NAMA_COOKIE, 'token-b')
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => $this->pegawai->nip], [
                'Accept' => 'application/json',
            ])
            ->assertOk();
    }

    protected function tarikPresensi(string $token): TestResponse
    {
        return $this->withCookie(KioskService::NAMA_COOKIE, $token)
            ->get('/kiosk/event/presensi', ['Accept' => 'application/json']);
    }
}
