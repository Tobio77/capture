<?php

namespace Tests\Feature;

use App\Enums\StatusKetepatan;
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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Jam kehadiran dicatat pada zona waktu setempat (FR-TAP-05, FR-TAP-07).
 *
 * Berkas ini mengunci satu bug yang sudah dua kali muncul dengan wajah
 * berbeda, dan keduanya berujung pada gejala yang sama: jam kehadiran meleset
 * tepat sebesar offset WIB, tetap sepanjang hari — "salah tetapi konsisten".
 *
 * Yang pertama (S28b) adalah aplikasi yang berjalan pada UTC; diperbaiki lewat
 * config('app.timezone').
 *
 * Yang kedua bertahan setelahnya dan tidak tertangkap sama sekali oleh
 * perbaikan itu: peramban mengirimkan `waktu_tap` sebagai ISO-8601 berakhiran
 * `Z`, Carbon mempertahankan zona UTC-nya, dan Eloquent menyimpan jam dinding
 * instance apa adanya. Tap pukul 09.00 WIB tersimpan 02:00.
 *
 * Yang rusak adalah jam yang TERSIMPAN, dan ia terbawa ke Daftar e-Presensi,
 * Rekap Absen, laporan, serta seluruh ekspor. Penilaian tepat/terlambat justru
 * kebetulan selamat, karena Carbon membandingkan instan alih-alih jam dinding
 * — sehingga barisnya tampil ganjil alih-alih salah: "01:00" berlabel
 * Terlambat terhadap batas 07.45. Kedua sifat itu diuji di bawah, supaya
 * perbaikan pada salah satunya tidak menutupi kemunduran pada yang lain.
 */
class ZonaWaktuAbsenTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-perangkat-uji';

    protected UnitKerja $unitKerja;

    protected EventAbsen $event;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AbsensiService::DISK);

        $this->unitKerja = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);

        $perangkat = Kiosk::factory()->diaktifkan(self::TOKEN)->create([
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        $this->event = EventAbsen::factory()->create([
            'tanggal' => '2026-09-07',
            'jam_mulai' => '07:30',
            'toleransi_menit' => 15,
        ]);
        $this->event->unitKerja()->attach($this->unitKerja);
        $this->gabungkanKeEvent($this->event, $perangkat);

        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        app(SettingAbsenService::class)->simpan(
            ['metode_wajah_aktif' => false],
            User::factory()->superadmin()->create(),
        );
    }

    /**
     * Kirim satu tap dengan `waktu_tap` apa adanya, seperti peramban.
     */
    protected function tap(string $waktuTap): void
    {
        $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->post('/kiosk/event/absen', [
                'id_card' => '199001012020011001',
                'jenis' => 'datang',
                'metode' => 'manual',
                'waktu_tap' => $waktuTap,
            ], ['Accept' => 'application/json'])
            ->assertOk();
    }

    #[Test]
    public function aplikasi_berjalan_pada_waktu_indonesia_barat(): void
    {
        // Prasyarat kedua test di bawah; bila ini bergeser, keduanya ikut
        // kehilangan maknanya tanpa memberi petunjuk apa pun.
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
    }

    #[Test]
    public function waktu_tap_bertanda_utc_disimpan_sebagai_jam_setempat(): void
    {
        $this->travelTo('2026-09-07 09:00:00');

        // 02:00Z adalah pukul 09.00 WIB — bentuk yang persis dikirim
        // `new Date().toISOString()` dari peramban perangkat.
        $this->tap('2026-09-07T02:00:00.000Z');

        $absensi = Absensi::sole();

        $this->assertSame('09:00', $absensi->waktu->format('H:i'));

        /*
         * Pembanding yang sama dengan yang dipakai menemukan bug ini di
         * produksi: `created_at` memakai Carbon::now() sehingga selalu benar,
         * dan selisih tujuh jam terhadapnya adalah tanda tangan bug-nya.
         */
        $this->assertSame(
            $absensi->created_at->format('H:i'),
            $absensi->waktu->format('H:i'),
        );
    }

    #[Test]
    public function jam_tersimpan_dan_status_ketepatan_menceritakan_hal_yang_sama(): void
    {
        /*
         * Pukul 08.00 WIB, lewat dari batas 07.30 + 15 menit.
         *
         * Statusnya sudah benar bahkan sebelum perbaikan zona — Carbon
         * membandingkan instan — tetapi jam tersimpannya 01:00. Baris seperti
         * itu membuat admin membaca "terlambat" pada jam yang jelas jauh
         * sebelum apel dimulai, dan yang dipertanyakan justru statusnya, bukan
         * jamnya. Keduanya karena itu diperiksa bersama.
         */
        $this->travelTo('2026-09-07 08:00:00');

        $this->tap('2026-09-07T01:00:00.000Z');

        $absensi = Absensi::sole();

        $this->assertSame('08:00', $absensi->waktu->format('H:i'));
        $this->assertSame(StatusKetepatan::Terlambat, $absensi->status_ketepatan);
    }

    #[Test]
    public function tap_pagi_buta_tidak_diam_diam_diganti_jam_server(): void
    {
        /*
         * Pukul 06.30 WIB masih tanggal 7 September, tetapi di UTC ia tanggal
         * 6. Tanpa penggeseran zona, `isSameDay()` menyimpulkan tap ini di luar
         * hari penyelenggaraan lalu menggantinya dengan jam server — sehingga
         * sebagian absen pada hari yang sama benar dan sebagian salah,
         * bergantung pukul berapa orangnya datang.
         *
         * Jam server sengaja dibuat jauh berbeda dari waktu tap supaya
         * penggantian diam-diam itu tidak dapat lolos sebagai kebetulan.
         */
        $this->travelTo('2026-09-07 09:15:00');

        $this->tap('2026-09-06T23:30:00.000Z');

        $this->assertSame('06:30', Absensi::sole()->waktu->format('H:i'));
    }

    #[Test]
    public function waktu_tap_tanpa_zona_tetap_dibaca_sebagai_jam_setempat(): void
    {
        /*
         * Antrian luring dapat menyimpan bentuk lain, dan sebagian peramban
         * lawas tidak menyertakan zona sama sekali. Nilai tanpa zona dibaca
         * pada zona aplikasi, bukan digeser lagi.
         */
        $this->travelTo('2026-09-07 07:20:00');

        $this->tap('2026-09-07 07:20:00');

        $this->assertSame('07:20', Absensi::sole()->waktu->format('H:i'));
    }
}
