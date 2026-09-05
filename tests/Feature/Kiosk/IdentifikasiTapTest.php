<?php

namespace Tests\Feature\Kiosk;

use App\Enums\CakupanEvent;
use App\Enums\JenisAbsen;
use App\Enums\StatusEvent;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\KioskService;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Identifikasi tap (kartu RFID maupun NIP) dan proxy foto pegawai
 * (FR-TAP-01, FR-TAP-03, NFR-04).
 */
class IdentifikasiTapTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-perangkat-uji';

    protected UnitKerja $unitKerja;

    protected EventAbsen $event;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.worka.api_url' => 'http://worka.test',
            'services.worka.api_token' => 'token-uji',
        ]);

        $this->unitKerja = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);

        $perangkat = Kiosk::factory()->diaktifkan(self::TOKEN)->create([
            'nama_titik' => 'Aula Senam BLK Surabaya',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        // Tap hanya dilayani selama ada event yang dibuka untuk unit ini
        // (FR-EVT-04); tanpa ini seluruh tap dijawab EVENT_TIDAK_AKTIF.
        $this->event = EventAbsen::factory()->create(['nama' => 'Apel Pagi']);
        $this->event->unitKerja()->attach($this->unitKerja);

        // Sejak revisi S29, tercakup saja tidak cukup: perangkat harus sudah
        // BERGABUNG ke event lewat kode unit kerja (FR-EVT-03).
        $this->gabungkanKeEvent($this->event, $perangkat);
    }

    protected function denganToken(): static
    {
        return $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN);
    }

    #[Test]
    public function nip_terdaftar_dijawab_dari_basis_data_lokal_tanpa_memanggil_worka(): void
    {
        Http::fake();

        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'nama' => 'Ahmad Fauzi',
            'jabatan' => 'Instruktur Pelatihan',
            'unit_kerja_id' => $this->unitKerja->id,
            'foto_tersedia_worka' => true,
        ]);

        $this->denganToken()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'nip' => '199001012020011001',
                    'nama' => 'Ahmad Fauzi',
                    'unit_kerja_kode' => 'BLK-SBY',
                    'unit_kerja_sama' => true,
                    'foto_tersedia' => true,
                ],
            ]);

        Http::assertNothingSent();
    }

    #[Test]
    public function layar_kiosk_membawa_event_dan_metode_yang_aktif(): void
    {
        // FR-SET-01: metode yang dimatikan admin tidak muncul di layar kiosk.
        app(SettingAbsenService::class)->simpan([
            'metode_manual_aktif' => true,
            'metode_rfid_aktif' => false,
            'metode_wajah_aktif' => true,
            'toleransi_default_menit' => 15,
            'ambang_kecocokan_wajah' => 85,
            'kompresi_foto' => 'sedang',
        ], User::factory()->superadmin()->create());

        $this->denganToken()
            ->get('/kiosk/event')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Kiosk/Utama')
                ->where('event.nama', 'Apel Pagi')
                ->where('metode.manual', true)
                ->where('metode.rfid', false)
                ->where('metode.wajah', true)
                ->has('daftar_presensi', 0)
                ->etc());
    }

    #[Test]
    public function layar_absen_event_menutup_diri_setelah_entry_ditutup(): void
    {
        /*
         * FR-EVT-04. Sejak layar Absen Event berdiri sendiri (revisi S29),
         * entry yang ditutup tidak lagi menyisakan layar tap kosong yang
         * tampak rusak di mata petugas: perangkat dipulangkan ke beranda,
         * tempat ia dapat memilih Absen Umum atau bergabung ke event lain.
         */
        $this->event->update(['status' => StatusEvent::Ditutup, 'ditutup_pada' => now()]);

        $this->denganToken()
            ->get('/kiosk/event')
            ->assertRedirect('/')
            ->assertSessionHas('gagal');
    }

    #[Test]
    public function layar_absen_umum_tetap_terbuka_tanpa_kegiatan_apa_pun(): void
    {
        /*
         * Absen Umum tidak terikat status event apa pun (revisi S29). Perangkat
         * yang tidak bergabung ke kegiatan mana pun tetap dapat mengabsen
         * harian — inilah yang memisahkannya dari Absen Event.
         */
        $this->event->update(['status' => StatusEvent::Ditutup, 'ditutup_pada' => now()]);

        $this->denganToken()
            ->get('/kiosk/umum')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Kiosk/Utama')
                ->where('mode', 'umum')
                ->etc());
    }

    #[Test]
    public function tap_kartu_rfid_mengenali_pemiliknya(): void
    {
        // Reader 13,56 MHz mengetikkan UID kartu, bukan NIP.
        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'nama' => 'Ahmad Fauzi',
            'uid_kartu' => '04A3B21C',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        $this->denganToken()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '04a3:b2:1c'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => ['nip' => '199001012020011001', 'nama' => 'Ahmad Fauzi', 'metode' => 'rfid'],
            ]);
    }

    #[Test]
    public function ketikan_nip_ditandai_sebagai_metode_manual(): void
    {
        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'uid_kartu' => '04A3B21C',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        // Pegawai berkartu yang mengetik NIP tetap tercatat sebagai manual.
        $this->denganToken()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['data' => ['metode' => 'manual']]);
    }

    #[Test]
    public function kartu_tak_dikenal_ditolak_dengan_kode_yang_dapat_dibaca_mesin(): void
    {
        $this->denganToken()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => 'DEADBEEF'], ['Accept' => 'application/json'])
            ->assertStatus(404)
            ->assertJson(['success' => false, 'code' => 'ID_TIDAK_DIKENAL']);
    }

    #[Test]
    public function jawaban_tap_membawa_embedding_referensi_pegawai_itu_saja(): void
    {
        // FR-TAP-04: pencocokan bersifat 1:1, jadi cukup satu deskriptor.
        $pegawai = Pegawai::factory()->wajahTerdaftar()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        Pegawai::factory()->wajahTerdaftar()->create([
            'nip' => '199001012020011002',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        $jawaban = $this->denganToken()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertOk();

        $data = $jawaban->json('data');

        $this->assertCount(128, $data['embedding_wajah']);
        $this->assertSame($pegawai->embedding_wajah, $data['embedding_wajah']);

        // Biometrik pegawai lain tidak ikut terkirim ke browser kiosk.
        $this->assertStringNotContainsString(
            (string) Pegawai::where('nip', '199001012020011002')->sole()->embedding_wajah[0],
            $jawaban->getContent(),
        );
    }

    #[Test]
    public function pegawai_tanpa_wajah_terdaftar_menjawab_embedding_kosong(): void
    {
        // Kiosk yang menerima null menampilkan pesan "wajah belum terdaftar",
        // bukan mencoba mencocokkan dengan data kosong.
        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'wajah_terdaftar' => false,
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        $this->denganToken()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['data' => ['wajah_terdaftar' => false, 'embedding_wajah' => null]]);
    }

    #[Test]
    public function layar_kiosk_membawa_ambang_kecocokan_dan_preset_kompresi(): void
    {
        // FR-SET-03 dan FR-SET-04 dipakai modul verifikasi di sisi klien.
        $this->denganToken()
            ->get('/kiosk/event')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ambang_kecocokan_wajah', 85)
                ->where('kompresi.dimensi_maks', 560)
                ->where('kompresi.kualitas', 75)
                ->etc());
    }

    #[Test]
    public function foto_referensi_tidak_pernah_ikut_dikirim_ke_kiosk(): void
    {
        // NFR-04 dan SDD §3: hanya embedding yang melintas ke jaringan lokal.
        $pegawai = Pegawai::factory()->wajahTerdaftar()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        $isi = $this->denganToken()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($pegawai->foto_referensi_path, $isi);
        $this->assertStringNotContainsString('foto_referensi_path', $isi);
    }

    #[Test]
    public function tap_mencatat_kiosk_sebagai_terhubung_pada_event(): void
    {
        // FR-EVT-03: alamat IP perangkat mengikuti aktivitas terbarunya —
        // satu perangkat dapat berpindah jaringan di tengah kegiatan, dan yang
        // dicari panitia saat menelusuri absen mencurigakan adalah alamat
        // terkininya, bukan alamat saat ia pertama bergabung.
        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        DB::table('event_kiosk')->update(['ip_address' => '10.10.0.9']);

        $this->denganToken()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertDatabaseHas('event_kiosk', [
            'event_absen_id' => $this->event->id,
            'kiosk_id' => Kiosk::sole()->id,
            'ip_address' => '127.0.0.1',
        ]);
    }

    #[Test]
    public function membuka_layar_event_tidak_pernah_menjadikan_perangkat_anggota(): void
    {
        /*
         * FR-EVT-03 (revisi S29). Keanggotaan lahir satu-satunya dari
         * penukaran kode unit kerja. Bila membuka layar saja sudah cukup untuk
         * tercatat sebagai anggota, kodenya kehilangan seluruh gunanya —
         * siapa pun yang tahu alamatnya menjadi titik absen event itu.
         */
        $lain = Kiosk::factory()->diaktifkan('token-perangkat-kedua')->create([
            'nama_titik' => 'Lobi BLK Surabaya',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        $this->withCookie(KioskService::NAMA_COOKIE, 'token-perangkat-kedua')
            ->get('/kiosk/event')
            ->assertRedirect('/');

        $this->assertDatabaseMissing('event_kiosk', ['kiosk_id' => $lain->id]);
    }

    #[Test]
    public function identifikasi_memberitahukan_jenis_yang_sudah_tercatat(): void
    {
        /*
         * FR-TAP-05 (revisi S28a). Layar titik absen memakai keterangan ini
         * untuk berhenti sebelum kamera menyala — memindai wajah orang yang
         * jelas sudah tercatat hanya memperlambat antrean, dan hasilnya toh
         * ditolak.
         */
        $pegawai = Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        $this->denganToken()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '199001012020011001'], [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertJson(['data' => ['sudah_absen' => ['datang' => null, 'pulang' => null]]]);

        Absensi::factory()->create([
            'event_absen_id' => $this->event->id,
            'pegawai_id' => $pegawai->id,
            'jenis' => JenisAbsen::Datang,
            'waktu' => $this->event->tanggal->toDateString().' 07:16:00',
        ]);

        $this->denganToken()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '199001012020011001'], [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->assertJson(['data' => ['sudah_absen' => ['datang' => '07:16', 'pulang' => null]]]);
    }

    #[Test]
    public function tap_ditolak_setelah_event_ditutup(): void
    {
        // FR-EVT-04: setelah entry ditutup, tap baru pada kiosk ditolak.
        // Absen umum dimatikan supaya yang diuji benar-benar keadaan tanpa
        // event; dengan absen umum menyala, tap justru dilayani sesi harian.
        $this->matikanAbsenUmum();

        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        $this->event->update(['status' => StatusEvent::Ditutup, 'ditutup_pada' => now()]);

        $this->denganToken()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'code' => 'EVENT_TIDAK_AKTIF',
            ]);
    }

    #[Test]
    public function tap_ditolak_bila_perangkat_belum_bergabung_ke_event(): void
    {
        /*
         * FR-EVT-03 (revisi S29). Sebelumnya cukup unit perangkat tercakup
         * event; kini tercakup TIDAK berarti melayani. Perangkat kedua di unit
         * yang sama — meja registrasi yang belum diberi kode — tidak boleh
         * ikut menampung tap kegiatan yang tidak dilayaninya.
         *
         * Absen umum dimatikan supaya yang diuji benar-benar penolakannya;
         * dengan absen umum menyala, tap perangkat ini tetap dilayani sesi
         * harian pada alamat yang berbeda.
         */
        $this->matikanAbsenUmum();

        Kiosk::factory()->diaktifkan('token-perangkat-kedua')->create([
            'nama_titik' => 'Lobi BLK Surabaya',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        $this->withCookie(KioskService::NAMA_COOKIE, 'token-perangkat-kedua')
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJson(['code' => 'EVENT_TIDAK_AKTIF']);
    }

    #[Test]
    public function event_semua_unit_melayani_kiosk_unit_mana_pun(): void
    {
        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        $this->event->unitKerja()->sync([]);
        $this->event->update(['cakupan' => CakupanEvent::SemuaUnit]);

        $this->denganToken()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    #[Test]
    public function jawaban_tap_menyertakan_event_yang_menampungnya(): void
    {
        // Kiosk tidak menyebutkan event mana yang dimaksud — FR-EVT-06
        // menjamin hanya ada satu yang aktif, jadi server yang menentukan.
        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        $this->denganToken()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson([
                'data' => [
                    'event' => ['id' => $this->event->id, 'nama' => 'Apel Pagi'],
                ],
            ]);
    }

    #[Test]
    public function nip_tak_dikenal_dijawab_dengan_kode_yang_dapat_dibaca_mesin(): void
    {
        $this->denganToken()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '199901012020011009'], ['Accept' => 'application/json'])
            ->assertNotFound()
            ->assertJson(['success' => false, 'code' => 'ID_TIDAK_DIKENAL']);
    }

    #[Test]
    public function pegawai_nonaktif_ditolak_bukan_dijawab_sukses(): void
    {
        Pegawai::factory()->create([
            'nip' => '198512312010011001',
            'unit_kerja_id' => $this->unitKerja->id,
            'aktif' => false,
        ]);

        $this->denganToken()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '198512312010011001'], ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJson(['success' => false, 'code' => 'PEGAWAI_TIDAK_AKTIF']);
    }

    #[Test]
    public function pegawai_dari_unit_lain_ditandai_berbeda_unit(): void
    {
        $unitLain = UnitKerja::factory()->create(['kode' => 'BLK-MJK']);

        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $unitLain->id,
        ]);

        $this->denganToken()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['data' => ['unit_kerja_sama' => false]]);
    }

    #[Test]
    public function perangkat_tanpa_token_tidak_dapat_memvalidasi_nip(): void
    {
        Pegawai::factory()->create(['nip' => '199001012020011001', 'unit_kerja_id' => $this->unitKerja->id]);

        $this->post('/kiosk/event/tap/identifikasi', ['id_card' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertRedirect('/kiosk/aktivasi');
    }

    #[Test]
    public function foto_di_proxy_dari_worka_tanpa_membocorkan_token(): void
    {
        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->unitKerja->id,
            'foto_tersedia_worka' => true,
        ]);

        Http::fake([
            'worka.test/api/v1/absen/pegawai/199001012020011001/foto' => Http::response(
                'isi-berkas-jpeg',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $respons = $this->denganToken()->get('/kiosk/event/pegawai/199001012020011001/foto');

        $respons->assertOk();
        $respons->assertHeader('Content-Type', 'image/jpeg');
        $respons->assertHeader('Cache-Control', 'max-age=3600, private');
        $this->assertSame('isi-berkas-jpeg', $respons->getContent());

        // Token WORKA hanya dipakai server, tidak pernah muncul di jawaban.
        $this->assertStringNotContainsString('token-uji', $respons->getContent());
    }

    #[Test]
    public function proxy_foto_menolak_nip_yang_tidak_dikenal_si_absen(): void
    {
        Http::fake();

        $this->denganToken()
            ->get('/kiosk/event/pegawai/199901012020011009/foto')
            ->assertNotFound();

        // Rute ini tidak boleh menjadi jalan memindai NIP di WORKA.
        Http::assertNothingSent();
    }

    #[Test]
    public function proxy_foto_tertutup_bagi_perangkat_tanpa_token(): void
    {
        Pegawai::factory()->create(['nip' => '199001012020011001', 'unit_kerja_id' => $this->unitKerja->id]);
        Http::fake();

        $this->get('/kiosk/event/pegawai/199001012020011001/foto')
            ->assertRedirect('/kiosk/aktivasi');

        Http::assertNothingSent();
    }
}
