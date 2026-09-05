<?php

namespace Tests\Feature;

use App\Enums\StatusEvent;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\KioskService;
use App\Services\PenggunaService;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Hardening keamanan (S26 — NFR-03, NFR-04).
 *
 * Berkas ini mengunci jaminan yang mudah tergerus tanpa disadari saat fitur
 * bertambah: sejauh mana sebuah perangkat absen boleh melihat data pegawai,
 * kapan data biometrik boleh meninggalkan server, dan apakah mencabut akses
 * benar-benar memutus akses.
 */
class HardeningKeamananTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-perangkat-uji';

    protected UnitKerja $upt;

    protected UnitKerja $unitLain;

    protected EventAbsen $event;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.worka.api_url' => 'http://worka.test',
            'services.worka.api_token' => 'token-uji',
        ]);

        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $this->upt = UnitKerja::factory()->create(['kode' => 'BLK-SGS', 'induk_id' => $opd->id]);
        $this->unitLain = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $opd->id]);

        $perangkat = Kiosk::factory()->diaktifkan(self::TOKEN)->create(['unit_kerja_id' => $this->upt->id]);

        $this->event = EventAbsen::factory()->create();
        $this->event->unitKerja()->attach($this->upt);

        // Sejak revisi S29, perangkat melayani event hanya setelah bergabung
        // lewat kode unit kerja (FR-EVT-03).
        $this->gabungkanKeEvent($this->event, $perangkat);
    }

    protected function denganPerangkat(): static
    {
        return $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN);
    }

    protected function aturWajah(bool $aktif): void
    {
        app(SettingAbsenService::class)->simpan([
            'metode_manual_aktif' => true,
            'metode_rfid_aktif' => true,
            'metode_wajah_aktif' => $aktif,
            'toleransi_default_menit' => 15,
            'ambang_kecocokan_wajah' => 85,
            'kompresi_foto' => 'sedang',
        ], User::factory()->superadmin()->create());
    }

    /* ---------------------------------------------------------------------
     * NFR-04 — sejauh mana perangkat boleh melihat data pegawai.
     * ------------------------------------------------------------------- */

    #[Test]
    public function perangkat_tidak_dapat_mengambil_foto_pegawai_di_luar_cakupan_eventnya(): void
    {
        /*
         * Tanpa pembatasan ini, satu perangkat yang dikuasai orang lain dapat
         * memanen foto seluruh pegawai dinas hanya dengan menelusuri NIP.
         */
        Http::fake();

        Pegawai::factory()->create([
            'nip' => '199001012020011009',
            'unit_kerja_id' => $this->unitLain->id,
        ]);

        $this->denganPerangkat()
            ->get('/kiosk/event/pegawai/199001012020011009/foto')
            ->assertNotFound();

        // WORKA tidak pernah dihubungi untuk pegawai di luar cakupan.
        Http::assertNothingSent();
    }

    #[Test]
    public function perangkat_dapat_mengambil_foto_pegawai_dalam_cakupan_eventnya(): void
    {
        Http::fake(['worka.test/*' => Http::response('biner-jpeg', 200, ['Content-Type' => 'image/jpeg'])]);

        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->upt->id,
        ]);

        $this->denganPerangkat()
            ->get('/kiosk/event/pegawai/199001012020011001/foto')
            ->assertOk();
    }

    #[Test]
    public function tanpa_event_aktif_perangkat_tidak_dapat_mengambil_foto_sama_sekali(): void
    {
        // Perangkat yang menganggur tidak punya alasan membuka data pegawai.
        Http::fake();

        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->upt->id,
        ]);

        /*
         * Entry ditutup, sehingga perangkat tidak lagi melayani event mana pun
         * — keanggotaannya pada `event_kiosk` tidak menolong, karena yang
         * dicari hanya event yang masih AKTIF (FR-EVT-04).
         */
        $this->event->update(['status' => StatusEvent::Ditutup, 'ditutup_pada' => now()]);
        $this->matikanAbsenUmum();

        $this->denganPerangkat()
            ->get('/kiosk/event/pegawai/199001012020011001/foto')
            ->assertNotFound();

        Http::assertNothingSent();
    }

    /* ---------------------------------------------------------------------
     * NFR-04 — kapan biometrik boleh meninggalkan server.
     * ------------------------------------------------------------------- */

    #[Test]
    public function embedding_tidak_dikirim_ketika_verifikasi_wajah_dimatikan(): void
    {
        // Tidak ada gunanya menaruh data biometrik pada perangkat yang memang
        // tidak akan memakainya.
        $this->aturWajah(false);

        Pegawai::factory()->wajahTerdaftar()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->upt->id,
        ]);

        $this->denganPerangkat()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['data' => ['embedding_wajah' => null]]);
    }

    #[Test]
    public function embedding_dikirim_ketika_verifikasi_wajah_menyala(): void
    {
        $this->aturWajah(true);

        Pegawai::factory()->wajahTerdaftar()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->upt->id,
        ]);

        $data = $this->denganPerangkat()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data');

        $this->assertCount(128, $data['embedding_wajah']);
    }

    #[Test]
    public function foto_referensi_dan_uid_kartu_tidak_pernah_ikut_jawaban_tap(): void
    {
        $this->aturWajah(true);

        Pegawai::factory()->wajahTerdaftar()->create([
            'nip' => '199001012020011001',
            'uid_kartu' => '04A3B21C',
            'unit_kerja_id' => $this->upt->id,
        ]);

        $isi = $this->denganPerangkat()
            ->post('/kiosk/event/tap/identifikasi', ['id_card' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertOk()
            ->getContent();

        // Foto referensi tidak pernah melintas; UID kartu adalah kredensial
        // fisik dan tidak perlu kembali ke perangkat.
        $this->assertStringNotContainsString('foto_referensi_path', $isi);
        $this->assertStringNotContainsString('04A3B21C', $isi);
    }

    /* ---------------------------------------------------------------------
     * NFR-03 — mencabut akses harus benar-benar memutus akses.
     * ------------------------------------------------------------------- */

    #[Test]
    public function device_token_tidak_pernah_tersimpan_mentah(): void
    {
        $perangkat = Kiosk::query()->sole();

        $this->assertNotSame(self::TOKEN, $perangkat->device_token);
        $this->assertSame(KioskService::hashToken(self::TOKEN), $perangkat->device_token);
    }

    #[Test]
    public function device_token_dan_kode_aktivasi_tidak_ikut_terserialisasi(): void
    {
        // Model disembunyikan agar tidak bocor lewat prop Inertia atau JSON
        // yang tanpa sengaja memuat seluruh atribut.
        $perangkat = Kiosk::query()->sole();
        $perangkat->forceFill(['kode_aktivasi' => 'ABCD2345'])->save();

        $json = $perangkat->fresh()->toJson();

        $this->assertStringNotContainsString('device_token', $json);
        $this->assertStringNotContainsString('kode_aktivasi"', $json);
        $this->assertStringNotContainsString('ABCD2345', $json);
    }

    #[Test]
    public function reset_sandi_memutus_sesi_yang_sedang_berjalan(): void
    {
        /*
         * Mengganti kata sandi saja tidak cukup: cookie sesi yang sudah terbit
         * tetap sah, sehingga akun yang disalahgunakan masih hidup di peramban
         * penyalahgunanya.
         */
        config(['session.driver' => 'database']);

        $sasaran = User::factory()->create();
        $this->buatBarisSesi($sasaran);

        app(PenggunaService::class)->resetSandi($sasaran, User::factory()->superadmin()->create());

        $this->assertSame(0, DB::table('sessions')->where('user_id', $sasaran->id)->count());
    }

    #[Test]
    public function menonaktifkan_akun_memutus_sesinya_di_tempat(): void
    {
        config(['session.driver' => 'database']);

        $sasaran = User::factory()->create();
        $this->buatBarisSesi($sasaran);

        app(PenggunaService::class)->ubahStatus($sasaran, false, User::factory()->superadmin()->create());

        $this->assertSame(0, DB::table('sessions')->where('user_id', $sasaran->id)->count());
    }

    #[Test]
    public function sesi_pengguna_lain_tidak_ikut_terputus(): void
    {
        config(['session.driver' => 'database']);

        $sasaran = User::factory()->create();
        $lain = User::factory()->create();

        $this->buatBarisSesi($sasaran);
        $this->buatBarisSesi($lain);

        app(PenggunaService::class)->resetSandi($sasaran, User::factory()->superadmin()->create());

        $this->assertSame(1, DB::table('sessions')->where('user_id', $lain->id)->count());
    }

    protected function buatBarisSesi(User $pengguna): void
    {
        DB::table('sessions')->insert([
            'id' => 'sesi-'.$pengguna->id,
            'user_id' => $pengguna->id,
            'ip_address' => '10.10.4.21',
            'user_agent' => 'uji',
            'payload' => 'kosong',
            'last_activity' => now()->timestamp,
        ]);
    }
}
