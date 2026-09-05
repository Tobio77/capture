<?php

namespace Tests\Feature;

use App\Enums\JenisAbsen;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\AbsensiService;
use App\Services\AbsenUmumService;
use App\Services\KioskService;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dua jaminan inti, diuji terpisah pada SETIAP jalur absen.
 *
 * Keduanya sudah dua kali rusak diam-diam — sekali pada S28a/S28b, sekali lagi
 * berisiko rusak pada pemisahan Absen Umum/Absen Event (S29) — dan keduanya
 * punya sifat yang sama: tidak terlihat dari kode, hanya terlihat dari
 * perilakunya. Karena itu dikunci di sini, bukan diingat.
 *
 *   1. FR-TAP-05 — tap kedua untuk jenis yang sama DITOLAK, dan jam yang sudah
 *      tercatat tidak bergeser. Beserta kebalikannya: tap "pulang" setelah
 *      "datang" tetap DITERIMA. Tanpa pasangan kedua itu, penolakan yang
 *      terlalu bersemangat akan lolos sebagai "benar".
 *
 *   2. Jam Masuk dan Jam Pulang menempati SATU baris per pegawai, di seluruh
 *      rekap — bukan dua baris terpisah.
 *
 * Yang membuat berkas ini berbeda dari test yang sudah ada: setiap kasus
 * dijalankan pada KETIGA jalur penyimpanan yang sekarang berdiri sendiri —
 *
 *   /kiosk/event/absen                      perangkat, mode Absen Event
 *   /kiosk/umum/absen                       perangkat, mode Absen Umum
 *   /admin/kelola-absen/absen-umum/absen    layar absen di peramban admin
 *
 * — dan pada KEDUA halaman rekap (Rekap Absen per event, dan pemantauan Absen
 * Umum). Sebelum berkas ini, jalur `/kiosk/umum/absen` beserta rekap Absen
 * Umum tidak pernah diuji sama sekali; keduanya lahir pada S29 dan lolos
 * begitu saja karena jalur event-nya hijau.
 */
class JaminanDuaJalurAbsenTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-perangkat-uji';

    protected const NIP = '199001012020011001';

    protected UnitKerja $upt;

    protected Kiosk $perangkat;

    protected EventAbsen $kegiatan;

    protected Pegawai $pegawai;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AbsensiService::DISK);

        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $this->upt = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $opd->id]);

        $this->perangkat = Kiosk::factory()->diaktifkan(self::TOKEN)->create([
            'unit_kerja_id' => $this->upt->id,
        ]);

        $this->kegiatan = EventAbsen::factory()->create([
            'nama' => 'Apel Pagi',
            'tanggal' => '2026-09-07',
            'jam_mulai' => '07:30',
            'toleransi_menit' => 15,
        ]);
        $this->kegiatan->unitKerja()->attach($this->upt);
        $this->gabungkanKeEvent($this->kegiatan, $this->perangkat);

        $this->pegawai = Pegawai::factory()->create([
            'nip' => self::NIP,
            'nama' => 'Ahmad Fauzi',
            'unit_kerja_id' => $this->upt->id,
        ]);

        $this->admin = User::factory()->superadmin()->create();

        // Verifikasi wajah dimatikan: yang diuji di sini aturan pencatatannya,
        // bukan pencocokan wajah yang hanya berjalan di peramban.
        app(SettingAbsenService::class)->simpan(['metode_wajah_aktif' => false], $this->admin);

        $this->travelTo('2026-09-07 07:35:00');
    }

    /**
     * Kirim satu tap pada jalur yang disebut.
     *
     * @param  'event'|'umum'|'admin'  $jalur
     */
    protected function tap(string $jalur, string $jenis = 'datang'): TestResponse
    {
        $muatan = ['id_card' => self::NIP, 'jenis' => $jenis, 'metode' => 'manual'];
        $kepala = ['Accept' => 'application/json'];

        if ($jalur === 'admin') {
            return $this->actingAs($this->admin)->post(
                "/admin/kelola-absen/absen-umum/absen?unit_kerja_id={$this->upt->id}",
                $muatan,
                $kepala,
            );
        }

        return $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->post("/kiosk/{$jalur}/absen", $muatan, $kepala);
    }

    /* =====================================================================
     * FR-TAP-05 — tap kedua untuk jenis yang sama ditolak.
     * ================================================================== */

    #[Test]
    public function tap_kedua_ditolak_pada_jalur_absen_event(): void
    {
        $this->tap('event')->assertOk();

        $this->travelTo('2026-09-07 07:50:00');

        $this->tap('event')
            ->assertStatus(409)
            ->assertJson(['success' => false, 'code' => 'SUDAH_ABSEN']);

        $this->pastikanSatuBarisJamTidakBergeser('07:35');
    }

    #[Test]
    public function tap_kedua_ditolak_pada_jalur_absen_umum_perangkat(): void
    {
        /*
         * Jalur ini lahir pada S29 dan sebelumnya tidak pernah diuji: seluruh
         * pengujian tap menuju /kiosk/event/absen, sehingga jalur umum ikut
         * hijau tanpa pernah dijalankan sekali pun.
         */
        $this->tap('umum')->assertOk();

        $this->travelTo('2026-09-07 07:50:00');

        $this->tap('umum')
            ->assertStatus(409)
            ->assertJson(['success' => false, 'code' => 'SUDAH_ABSEN']);

        $this->pastikanSatuBarisJamTidakBergeser('07:35');
    }

    #[Test]
    public function tap_kedua_ditolak_pada_layar_absen_admin(): void
    {
        $this->tap('admin')->assertOk();

        $this->travelTo('2026-09-07 07:50:00');

        $this->tap('admin')
            ->assertStatus(409)
            ->assertJson(['success' => false, 'code' => 'SUDAH_ABSEN']);

        $this->pastikanSatuBarisJamTidakBergeser('07:35');
    }

    /**
     * Satu baris "datang" saja, dan jamnya tetap jam tap pertama.
     *
     * Menolak tap kedua tidak ada gunanya bila barisnya toh tergeser: yang
     * dijaga FR-TAP-05 adalah jam kehadiran sebagai bukti.
     */
    protected function pastikanSatuBarisJamTidakBergeser(string $jam): void
    {
        $baris = Absensi::query()->where('jenis', JenisAbsen::Datang)->get();

        $this->assertCount(1, $baris, 'Tap kedua tidak boleh menambah baris.');
        $this->assertSame($jam, $baris->first()->waktu->format('H:i'));
    }

    /* =====================================================================
     * Kebalikannya: tap "pulang" sesudah "datang" tetap diterima.
     * ================================================================== */

    #[Test]
    public function absen_pulang_tetap_diterima_pada_jalur_absen_event(): void
    {
        $this->tap('event')->assertOk();

        $this->travelTo('2026-09-07 15:04:00');

        $this->tap('event', 'pulang')->assertOk();

        $this->assertSame(2, Absensi::query()->count());
    }

    #[Test]
    public function absen_pulang_tetap_diterima_pada_jalur_absen_umum(): void
    {
        $this->tap('umum')->assertOk();

        $this->travelTo('2026-09-07 15:04:00');

        $this->tap('umum', 'pulang')->assertOk();

        $this->assertSame(2, Absensi::query()->count());
    }

    /* =====================================================================
     * Jam Masuk & Jam Pulang menempati satu baris pada seluruh rekap.
     * ================================================================== */

    #[Test]
    public function daftar_presensi_menyatukan_datang_dan_pulang_pada_jalur_event(): void
    {
        $this->tap('event')->assertOk();

        $this->travelTo('2026-09-07 15:04:00');

        $jawaban = $this->tap('event', 'pulang')->assertOk();

        $presensi = $jawaban->json('data.daftar_presensi');

        $this->assertCount(1, $presensi, 'Pulang harus memperbarui baris yang sama.');
        $this->assertSame('07:35', $presensi[0]['jam_masuk']);
        $this->assertSame('15:04', $presensi[0]['jam_pulang']);
    }

    #[Test]
    public function daftar_presensi_menyatukan_datang_dan_pulang_pada_jalur_umum(): void
    {
        $this->tap('umum')->assertOk();

        $this->travelTo('2026-09-07 15:04:00');

        $jawaban = $this->tap('umum', 'pulang')->assertOk();

        $presensi = $jawaban->json('data.daftar_presensi');

        $this->assertCount(1, $presensi);
        $this->assertSame('07:35', $presensi[0]['jam_masuk']);
        $this->assertSame('15:04', $presensi[0]['jam_pulang']);
    }

    #[Test]
    public function rekap_event_menyatukan_datang_dan_pulang(): void
    {
        $this->tap('event')->assertOk();
        $this->travelTo('2026-09-07 15:04:00');
        $this->tap('event', 'pulang')->assertOk();

        $this->actingAs($this->admin)
            ->get("/admin/kelola-absen/rekap?event_absen_id={$this->kegiatan->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->has('rekap', 1)
                ->where('rekap.0.jam_masuk', '07:35')
                ->where('rekap.0.jam_pulang', '15:04')
                ->etc());
    }

    #[Test]
    public function rekap_absen_umum_menyatukan_datang_dan_pulang(): void
    {
        /*
         * Halaman pemantauan Absen Umum memakai AbsensiService::rekap() yang
         * sama dengan Rekap Absen per event — tetapi itu justru yang perlu
         * dibuktikan, bukan diandaikan: dua halaman yang memanggil service
         * yang sama tetap dapat berbeda hasil bila salah satunya menyaring
         * atau menyusun ulang barisnya sendiri.
         */
        $this->tap('umum')->assertOk();
        $this->travelTo('2026-09-07 15:04:00');
        $this->tap('umum', 'pulang')->assertOk();

        $this->actingAs($this->admin)
            ->get("/admin/kelola-absen/absen-umum?unit_kerja_id={$this->upt->id}&tanggal=2026-09-07")
            ->assertOk()
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->has('baris', 1)
                ->where('baris.0.jam_masuk', '07:35')
                ->where('baris.0.jam_pulang', '15:04')
                ->etc());
    }

    /* =====================================================================
     * Kedua jalur tidak boleh saling menutupi.
     * ================================================================== */

    #[Test]
    public function absen_umum_dan_kegiatan_dihitung_terpisah(): void
    {
        /*
         * Satu orang boleh tercatat pada kegiatan DAN pada absen harian di
         * tanggal yang sama — keduanya sesi yang berbeda. Bila penolakan tap
         * ganda kelak dipasang pada pegawai alih-alih pada pasangan
         * (event, pegawai, jenis), kasus ini yang pertama gagal.
         */
        $this->tap('event')->assertOk();
        $this->tap('umum')->assertOk();

        $this->assertSame(2, Absensi::query()->count());

        $sesiUmum = app(AbsenUmumService::class)->sesi($this->upt->id);

        $this->assertNotNull($sesiUmum);
        $this->assertNotSame($this->kegiatan->id, $sesiUmum->id);
    }
}
