<?php

namespace Tests\Feature\Kiosk;

use App\Enums\JenisAbsen;
use App\Enums\StatusEvent;
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
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penyimpanan hasil absen dari kiosk (FR-TAP-05 s.d. FR-TAP-07, NFR-04).
 */
class SimpanAbsenTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-perangkat-uji';

    protected UnitKerja $unitKerja;

    protected EventAbsen $event;

    protected Pegawai $pegawai;

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

        // Sejak revisi S29, perangkat melayani event hanya setelah bergabung
        // lewat kode unit kerja (FR-EVT-03).
        $this->gabungkanKeEvent($this->event, $perangkat);

        $this->pegawai = Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'nama' => 'Ahmad Fauzi',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        // Verifikasi wajah dimatikan pada sebagian besar test agar yang diuji
        // benar-benar logika pencatatannya; test khusus menyalakannya sendiri.
        $this->aturSetting(wajah: false);
    }

    protected function aturSetting(bool $wajah, int $ambang = 85): void
    {
        app(SettingAbsenService::class)->simpan([
            'metode_manual_aktif' => true,
            'metode_rfid_aktif' => true,
            'metode_wajah_aktif' => $wajah,
            'toleransi_default_menit' => 15,
            'ambang_kecocokan_wajah' => $ambang,
            'kompresi_foto' => 'sedang',
        ], User::factory()->superadmin()->create());
    }

    protected function denganToken(): static
    {
        return $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN);
    }

    /**
     * @param  array<string, mixed>  $ubahan
     */
    protected function kirim(array $ubahan = []): TestResponse
    {
        return $this->denganToken()->post('/kiosk/event/absen', array_merge([
            'id_card' => '199001012020011001',
            'jenis' => 'datang',
            'metode' => 'manual',
        ], $ubahan), ['Accept' => 'application/json']);
    }

    /** JPEG 1x1 piksel sebagai data URI. */
    protected function fotoUji(): string
    {
        return 'data:image/jpeg;base64,'.base64_encode(
            base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==')
        );
    }

    #[Test]
    public function absen_datang_tersimpan_beserta_status_ketepatan(): void
    {
        // Tap 07:40, jam mulai 07:30 + toleransi 15 menit -> masih tepat.
        $this->travelTo('2026-09-07 07:40:00');

        $this->kirim()
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['status_ketepatan' => 'tepat']]);

        $absensi = Absensi::sole();

        $this->assertSame($this->event->id, $absensi->event_absen_id);
        $this->assertSame($this->pegawai->id, $absensi->pegawai_id);
        $this->assertSame(JenisAbsen::Datang, $absensi->jenis);
        $this->assertSame(StatusKetepatan::Tepat, $absensi->status_ketepatan);
    }

    #[Test]
    public function tap_melewati_toleransi_dicatat_terlambat(): void
    {
        // FR-TAP-07: 07:46 melewati 07:30 + 15 menit.
        $this->travelTo('2026-09-07 07:46:00');

        $this->kirim()->assertOk()->assertJson(['data' => ['status_ketepatan' => 'terlambat']]);

        $this->assertSame(StatusKetepatan::Terlambat, Absensi::sole()->status_ketepatan);
    }

    #[Test]
    public function absen_pulang_tidak_mengenal_status_ketepatan(): void
    {
        $this->travelTo('2026-09-07 16:00:00');

        $this->kirim(['jenis' => 'pulang'])->assertOk();

        $this->assertNull(Absensi::sole()->status_ketepatan);
    }

    #[Test]
    public function tap_kedua_jenis_sama_ditolak_dan_tidak_menggeser_jam(): void
    {
        /*
         * FR-TAP-05 (revisi S28a): tap kedua untuk jenis yang sama DITOLAK,
         * bukan menimpa yang pertama. Jam kehadiran adalah bukti; membiarkannya
         * tergeser oleh tap ulang berarti siapa pun dapat memindahkan jamnya
         * sendiri — termasuk dari 'tepat waktu' menjadi jam yang lebih enak
         * dibaca, atau sebaliknya.
         */
        $this->travelTo('2026-09-07 07:35:00');
        $this->kirim()->assertOk();

        $this->travelTo('2026-09-07 07:50:00');
        $this->kirim()
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'code' => 'SUDAH_ABSEN',
                'message' => 'Sudah absen datang pukul 07:35.',
            ]);

        $this->assertDatabaseCount('absensi', 1);

        // Jam dan ketepatan tetap milik tap pertama.
        $absensi = Absensi::sole();
        $this->assertSame('07:35', $absensi->waktu->format('H:i'));
        $this->assertSame(StatusKetepatan::Tepat, $absensi->status_ketepatan);
    }

    #[Test]
    public function absen_pulang_tetap_diterima_setelah_absen_datang(): void
    {
        // Yang ditolak hanya jenis yang sama; datang dan pulang berdiri sendiri.
        $this->travelTo('2026-09-07 07:35:00');
        $this->kirim()->assertOk();

        $this->travelTo('2026-09-07 15:05:00');
        $this->kirim(['jenis' => 'pulang'])->assertOk();

        $this->assertDatabaseCount('absensi', 2);
    }

    #[Test]
    public function datang_dan_pulang_menempati_satu_baris_daftar_presensi(): void
    {
        $this->travelTo('2026-09-07 07:35:00');
        $this->kirim();

        $this->travelTo('2026-09-07 16:05:00');
        $jawaban = $this->kirim(['jenis' => 'pulang'])->assertOk();

        // Dua baris absensi, satu baris tampilan (UIUX §4.2.2).
        $this->assertDatabaseCount('absensi', 2);

        $daftar = $jawaban->json('data.daftar_presensi');

        $this->assertCount(1, $daftar);
        $this->assertSame('07:35', $daftar[0]['jam_masuk']);
        $this->assertSame('16:05', $daftar[0]['jam_pulang']);
        $this->assertSame('Ahmad Fauzi', $daftar[0]['nama']);
    }

    #[Test]
    public function absen_ditolak_setelah_event_ditutup(): void
    {
        // FR-EVT-04 diuji pada keadaan tanpa event sama sekali; dengan absen
        // umum menyala, tap sesudah entry ditutup dilayani sesi harian.
        $this->matikanAbsenUmum();

        $this->event->update(['status' => StatusEvent::Ditutup, 'ditutup_pada' => now()]);

        $this->kirim()
            ->assertStatus(409)
            ->assertJson(['code' => 'EVENT_TIDAK_AKTIF']);

        $this->assertDatabaseCount('absensi', 0);
    }

    #[Test]
    public function skor_di_bawah_ambang_ditolak_server_walau_kiosk_mengirimkannya(): void
    {
        // Keputusan kiosk tidak dipercaya sendirian (SDD §1.2).
        $this->aturSetting(wajah: true, ambang: 85);

        $this->kirim(['skor' => 80])
            ->assertStatus(422)
            ->assertJson(['code' => 'WAJAH_TIDAK_COCOK']);

        $this->assertDatabaseCount('absensi', 0);
    }

    #[Test]
    public function absen_tanpa_skor_ditolak_saat_verifikasi_wajah_menyala(): void
    {
        $this->aturSetting(wajah: true);

        $this->kirim()
            ->assertStatus(422)
            ->assertJson(['code' => 'WAJAH_BELUM_DIVERIFIKASI']);
    }

    #[Test]
    public function skor_tidak_disimpan_saat_verifikasi_wajah_dimatikan(): void
    {
        // Kiosk yang tetap mengirim skor tidak membuatnya tercatat.
        $this->kirim(['skor' => 99])->assertOk();

        $this->assertNull(Absensi::sole()->skor_kecocokan_wajah);
    }

    #[Test]
    public function pegawai_nonaktif_tidak_dapat_diabsenkan(): void
    {
        $this->pegawai->update(['aktif' => false]);

        $this->kirim()->assertStatus(403)->assertJson(['code' => 'PEGAWAI_TIDAK_AKTIF']);
        $this->assertDatabaseCount('absensi', 0);
    }

    #[Test]
    public function kartu_tak_dikenal_tidak_mencatat_apa_pun(): void
    {
        $this->kirim(['id_card' => 'DEADBEEF'])
            ->assertStatus(404)
            ->assertJson(['code' => 'ID_TIDAK_DIKENAL']);
    }

    #[Test]
    public function foto_absen_tersimpan_pada_disk_privat(): void
    {
        $this->kirim(['foto' => $this->fotoUji()])->assertOk();

        $absensi = Absensi::sole();

        $this->assertNotNull($absensi->foto_path);
        $this->assertStringStartsWith('foto-absen/', $absensi->foto_path);

        Storage::disk(AbsensiService::DISK)->assertExists($absensi->foto_path);
    }

    #[Test]
    public function foto_yang_melampaui_batas_penyimpanan_ditolak(): void
    {
        // NFR-06: kiriman yang jauh di luar preset berarti preset diabaikan.
        $besar = 'data:image/jpeg;base64,'.base64_encode(random_bytes(AbsensiService::BATAS_FOTO_BYTE + 1024));

        $this->kirim(['foto' => $besar])->assertStatus(422);
        $this->assertDatabaseCount('absensi', 0);
    }

    #[Test]
    public function berkas_selain_jpeg_ditolak(): void
    {
        $this->kirim(['foto' => 'data:text/html;base64,'.base64_encode('<script>')])
            ->assertStatus(422);
    }

    /**
     * Siapkan satu baris absensi berfoto tanpa melewati HTTP, supaya test
     * tanpa token benar-benar berangkat tanpa cookie perangkat.
     */
    protected function absensiBerfoto(): Absensi
    {
        $path = 'foto-absen/uji.jpg';

        Storage::disk(AbsensiService::DISK)->put($path, 'biner-jpeg');

        return Absensi::factory()->create([
            'event_absen_id' => $this->event->id,
            'pegawai_id' => $this->pegawai->id,
            'foto_path' => $path,
        ]);
    }

    #[Test]
    public function foto_absen_tertutup_bagi_perangkat_tanpa_token(): void
    {
        // NFR-04: berkas foto tidak dapat diakses tanpa autentikasi.
        $absensi = $this->absensiBerfoto();

        $this->get("/kiosk/event/absen/{$absensi->id}/foto")->assertRedirect('/kiosk/aktivasi');
    }

    #[Test]
    public function kiosk_pada_event_yang_sama_dapat_mengambil_foto_absen(): void
    {
        $absensi = $this->absensiBerfoto();

        $this->denganToken()->get("/kiosk/event/absen/{$absensi->id}/foto")->assertOk();
    }

    #[Test]
    public function kiosk_unit_lain_tidak_dapat_mengambil_foto_absen(): void
    {
        $absensi = $this->absensiBerfoto();

        // Perangkat di unit lain tidak berkepentingan atas foto kehadiran ini.
        $unitLain = UnitKerja::factory()->create(['kode' => 'BLK-MJK']);
        Kiosk::factory()->diaktifkan('token-kiosk-lain')->create(['unit_kerja_id' => $unitLain->id]);

        $this->withCookie(KioskService::NAMA_COOKIE, 'token-kiosk-lain')
            ->get("/kiosk/event/absen/{$absensi->id}/foto")
            ->assertForbidden();
    }

    #[Test]
    public function tap_kedua_tidak_menyisakan_foto_yatim(): void
    {
        /*
         * Tap kedua ditolak, tetapi fotonya telanjur sampai ke server dan
         * telanjur ditulis ke disk sebelum kunci unik menolaknya. Berkas itu
         * harus ikut dibuang: tidak ada baris absensi yang menautnya, dan ia
         * akan menumpuk diam-diam pada setiap tap ulang.
         */
        $this->kirim(['foto' => $this->fotoUji()])->assertOk();
        $pathPertama = Absensi::sole()->foto_path;

        $this->kirim(['foto' => $this->fotoUji()])->assertStatus(409);

        $disk = Storage::disk(AbsensiService::DISK);

        // Foto tap pertama tetap utuh — ia bukti kehadiran yang sah.
        $this->assertSame($pathPertama, Absensi::sole()->foto_path);
        $disk->assertExists($pathPertama);

        // Dan tidak ada berkas kedua yang tertinggal tanpa pemilik.
        $this->assertCount(1, $disk->allFiles(AbsensiService::DIREKTORI));
    }

    #[Test]
    public function absen_dari_antrian_luring_dicatat_pada_jam_tapnya(): void
    {
        /*
         * NFR-05: tap terjadi 07:35 tetapi baru terkirim 08:10 setelah
         * jaringan pulih. Mencatatnya pada jam pengiriman akan mengubah
         * kehadiran yang tepat waktu menjadi terlambat.
         */
        $this->travelTo('2026-09-07 08:10:00');

        $this->kirim(['waktu_tap' => '2026-09-07T07:35:00+07:00'])->assertOk();

        $absensi = Absensi::sole();

        $this->assertSame('07:35', $absensi->waktu->format('H:i'));
        $this->assertSame(StatusKetepatan::Tepat, $absensi->status_ketepatan);
    }

    #[Test]
    public function waktu_tap_di_masa_depan_diabaikan(): void
    {
        // Jam kiosk yang melenceng jauh tidak boleh menggeser kehadiran.
        $this->travelTo('2026-09-07 07:40:00');

        $this->kirim(['waktu_tap' => '2026-09-07T23:00:00+07:00'])->assertOk();

        $this->assertSame('07:40', Absensi::sole()->waktu->format('H:i'));
    }

    #[Test]
    public function waktu_tap_di_luar_hari_event_diabaikan(): void
    {
        $this->travelTo('2026-09-07 07:40:00');

        $this->kirim(['waktu_tap' => '2026-09-06T07:35:00+07:00'])->assertOk();

        $this->assertSame('07:40', Absensi::sole()->waktu->format('H:i'));
    }

    #[Test]
    public function pengiriman_ulang_dari_antrian_tidak_menduplikasi_baris(): void
    {
        /*
         * Antrian luring boleh mengirim ulang berkali-kali. Kiriman kembar
         * ditolak sebagai tap kedua (FR-TAP-05 revisi S28a) — bukan galat
         * server, sehingga klien membuangnya dari antrian alih-alih mencoba
         * selamanya, dan baris pertamanya tetap utuh pada jam tap aslinya.
         */
        $this->travelTo('2026-09-07 08:10:00');

        $muatan = ['waktu_tap' => '2026-09-07T07:35:00+07:00'];

        $this->kirim($muatan)->assertOk();
        $this->kirim($muatan)->assertStatus(409);
        $this->kirim($muatan)->assertStatus(409);

        $this->assertDatabaseCount('absensi', 1);
        $this->assertSame('07:35', Absensi::sole()->waktu->format('H:i'));
    }

    #[Test]
    public function penarikan_berkala_membawa_absen_dari_kiosk_lain(): void
    {
        // FR-TAP-08: daftar bertambah seiring pegawai lain men-tap di kiosk
        // lain pada event yang sama.
        $lain = Pegawai::factory()->create([
            'nip' => '199001012020011002',
            'nama' => 'Dewi Anggraini',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        Absensi::factory()->create([
            'event_absen_id' => $this->event->id,
            'pegawai_id' => $lain->id,
            'waktu' => '2026-09-07 07:31:00',
        ]);

        $this->denganToken()
            ->get('/kiosk/event/presensi', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson([
                'event' => ['id' => $this->event->id],
                'daftar_presensi' => [['nama' => 'Dewi Anggraini', 'jam_masuk' => '07:31']],
            ]);
    }

    #[Test]
    public function penarikan_berkala_menyatakan_event_null_setelah_ditutup(): void
    {
        // Layar kiosk memakai ini untuk mengunci kolom tap tanpa dimuat ulang.
        $this->event->update(['status' => StatusEvent::Ditutup, 'ditutup_pada' => now()]);

        $this->denganToken()
            ->get('/kiosk/event/presensi', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['event' => null, 'daftar_presensi' => []]);
    }

    #[Test]
    public function daftar_presensi_tertutup_bagi_perangkat_tanpa_token(): void
    {
        $this->get('/kiosk/event/presensi')->assertRedirect('/kiosk/aktivasi');
    }

    #[Test]
    public function daftar_presensi_tidak_menduplikasi_baris_setelah_tap_berulang(): void
    {
        // FR-TAP-05 dilihat dari sisi tampilan: berapa kali pun di-tap, satu
        // pegawai tetap satu baris.
        $this->travelTo('2026-09-07 07:35:00');
        $this->kirim();
        $this->kirim();

        $this->travelTo('2026-09-07 16:00:00');
        $this->kirim(['jenis' => 'pulang']);
        $this->kirim(['jenis' => 'pulang']);

        $daftar = $this->denganToken()->get('/kiosk/event/presensi', ['Accept' => 'application/json'])->json('daftar_presensi');

        $this->assertCount(1, $daftar);
        $this->assertSame('16:00', $daftar[0]['jam_pulang']);
    }

    #[Test]
    public function layar_kiosk_memuat_daftar_presensi_yang_sudah_ada(): void
    {
        $this->travelTo('2026-09-07 07:35:00');
        $this->kirim()->assertOk();

        $this->denganToken()
            ->get('/kiosk/event')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('daftar_presensi', 1)
                ->where('daftar_presensi.0.jam_masuk', '07:35')
                ->etc());
    }
}
