<?php

namespace Tests\Feature;

use App\Enums\AksiLog;
use App\Enums\StatusEvent;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\LogAktivitas;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\KioskService;
use App\Services\PenggunaService;
use App\Services\PerhatianDashboardService;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
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
    public function embedding_tidak_pernah_meninggalkan_server(): void
    {
        /*
         * Kebijakannya berubah pada audit pra-deploy, dan arahnya satu:
         * TIDAK PERNAH, apa pun keadaan settingnya.
         *
         * Sebelumnya deskriptor 128 dimensi dikirim ke peramban ketika
         * verifikasi wajah menyala, supaya pencocokan 1:1 dapat dilakukan di
         * sana. Itu membuat pemeriksaan ulang di server mustahil dipercaya:
         * peramban yang sudah memegang vektor referensinya cukup
         * memantulkannya kembali sebagai "hasil capture" miliknya untuk
         * memperoleh jarak nol dan skor sempurna.
         *
         * Kini server yang mencocokkan, dan tidak ada satu pun biometrik yang
         * perlu berada di titik absen.
         */
        foreach ([true, false] as $verifikasiMenyala) {
            $this->aturWajah($verifikasiMenyala);

            $pegawai = Pegawai::factory()->wajahTerdaftar()->create([
                'nip' => $verifikasiMenyala ? '199001012020011001' : '199001012020011002',
                'unit_kerja_id' => $this->upt->id,
            ]);

            $jawaban = $this->denganPerangkat()
                ->post(
                    '/kiosk/event/tap/identifikasi',
                    ['id_card' => $pegawai->nip],
                    ['Accept' => 'application/json'],
                )
                ->assertOk();

            $this->assertArrayNotHasKey('embedding_wajah', $jawaban->json('data'));

            // Bukan sekadar medannya yang hilang: tidak satu pun angkanya
            // muncul di badan jawaban, lewat nama medan apa pun.
            $this->assertStringNotContainsString(
                (string) $pegawai->embedding_wajah[0],
                $jawaban->getContent(),
            );
        }
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
    /* ---------------------------------------------------------------------
     * Perbaikan audit pra-deploy.
     * ------------------------------------------------------------------- */

    #[Test]
    public function setiap_jawaban_membawa_header_keamanan(): void
    {
        /*
         * Perbaikan M-2. Yang paling nyata di antaranya X-Frame-Options:
         * tanpa itu panel admin dapat dibingkai halaman lain, dan admin yang
         * sedang login dapat dipancing menekan sakelar Mode Terbuka tanpa
         * pernah melihat layar yang sebenarnya ia sentuh.
         */
        $jawaban = $this->actingAs(User::factory()->superadmin()->create())
            ->get('/admin/dashboard')
            ->assertOk();

        $jawaban->assertHeader('X-Frame-Options', 'DENY');
        $jawaban->assertHeader('X-Content-Type-Options', 'nosniff');
        $jawaban->assertHeader('Referrer-Policy', 'same-origin');

        // Kamera hanya untuk aplikasi ini sendiri; sisanya ditutup.
        $this->assertStringContainsString(
            'camera=(self)',
            $jawaban->headers->get('Permissions-Policy'),
        );
        $this->assertStringContainsString(
            'microphone=()',
            $jawaban->headers->get('Permissions-Policy'),
        );
    }

    #[Test]
    public function header_keamanan_juga_menempel_pada_halaman_publik(): void
    {
        // Halaman depan terbuka tanpa autentikasi apa pun; justru di sanalah
        // pembingkaian paling mudah dicoba.
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    #[Test]
    public function hsts_tidak_dikirim_lewat_sambungan_tak_terenkripsi(): void
    {
        /*
         * Peramban mengabaikan HSTS dari sambungan HTTP, jadi mengirimkannya
         * tidak ada gunanya — tetapi memasangnya di lingkungan pengembangan
         * yang berjalan di HTTP akan mengunci capture.test ke HTTPS pada
         * peramban pengembang selama setahun.
         */
        $this->assertNull(
            $this->get('http://capture.test/')->headers->get('Strict-Transport-Security'),
        );

        // Dan sebaliknya: begitu sambungannya aman, HSTS ikut terpasang.
        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            $this->get('https://capture.test/')->headers->get('Strict-Transport-Security'),
        );
    }

    #[Test]
    public function kode_aktivasi_tidak_pernah_tersimpan_apa_adanya(): void
    {
        /*
         * Perbaikan L-1. Risikonya memang kecil — sekali pakai, berlaku 24
         * jam — tetapi siapa pun yang dapat membaca tabel `kiosk` dapat
         * mengaktifkan perangkat atas nama titik absen mana pun, dan
         * perangkat yang lahir dari situ terlihat sah di Daftar Perangkat.
         */
        $kiosk = Kiosk::factory()->menungguAktivasi('ABCD2345')->create([
            'unit_kerja_id' => $this->upt->id,
        ]);

        $tersimpan = DB::table('kiosk')->where('id', $kiosk->id)->value('kode_aktivasi');

        $this->assertNotSame('ABCD2345', $tersimpan);
        $this->assertSame(KioskService::hashToken('ABCD2345'), $tersimpan);

        // Dan kodenya tetap dapat ditukarkan seperti biasa.
        $this->post('/kiosk/aktivasi', ['kode_aktivasi' => 'ABCD2345'])
            ->assertRedirect('/');
    }

    #[Test]
    public function baris_audit_tidak_dapat_dihapus_lewat_aplikasi(): void
    {
        /*
         * Perbaikan L-3. Sifat append-only sebelumnya benar karena tidak ada
         * kode yang pernah menulisnya, bukan karena ada yang mencegahnya —
         * dan jejak audit yang bergantung pada ketiadaan kode adalah jejak
         * yang akan hilang pada sesi ke sekian.
         *
         * Ini pagar lapis APLIKASI; ia tidak menghentikan siapa pun yang
         * sudah memegang akses SQL langsung.
         */
        $baris = LogAktivitas::query()->create([
            'aksi' => AksiLog::Masuk,
            'deskripsi' => 'Uji jejak audit.',
        ]);

        try {
            $baris->delete();
            $this->fail('Baris audit seharusnya tidak dapat dihapus.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $baris->update(['deskripsi' => 'Disunting diam-diam.']);
            $this->fail('Baris audit seharusnya tidak dapat diubah.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertDatabaseHas('log_aktivitas', ['deskripsi' => 'Uji jejak audit.']);
    }

    #[Test]
    public function mematikan_verifikasi_wajah_memasang_peringatan_dan_mencatat_waktunya(): void
    {
        /*
         * Perbaikan H-3. Sampai audit pra-deploy, verifikasi wajah yang
         * dimatikan tidak memunculkan peringatan di mana pun — hanya satu
         * sakelar di halaman Setting yang harus sengaja dibuka untuk dilihat.
         */
        $admin = User::factory()->superadmin()->create();

        $this->aturWajah(false);

        // Spanduk di kerangka Panel Admin, terlihat di setiap halaman.
        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman->where('verifikasi_wajah_mati', true)->etc());

        // Dan butirnya di panel Perhatian, yang menyebut berapa lama.
        $perhatian = collect(
            app(PerhatianDashboardService::class)->untuk($admin),
        );

        $this->assertTrue($perhatian->contains('jenis', 'verifikasi_wajah_mati'));

        $this->assertNotNull(
            app(SettingAbsenService::class)
                ->dilonggarkanSejak(SettingAbsenService::KUNCI_WAJAH_MATI_SEJAK),
        );
    }

    #[Test]
    public function menyalakan_kembali_verifikasi_wajah_menghapus_peringatannya(): void
    {
        $admin = User::factory()->superadmin()->create();

        $this->aturWajah(false);
        $this->aturWajah(true);

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman->where('verifikasi_wajah_mati', false)->etc());

        $perhatian = collect(
            app(PerhatianDashboardService::class)->untuk($admin),
        );

        $this->assertFalse($perhatian->contains('jenis', 'verifikasi_wajah_mati'));

        // Stempel waktunya ikut dihapus, supaya pemulihan yang benar tidak
        // meninggalkan peringatan yang menggantung.
        $this->assertNull(
            app(SettingAbsenService::class)
                ->dilonggarkanSejak(SettingAbsenService::KUNCI_WAJAH_MATI_SEJAK),
        );
    }
}
