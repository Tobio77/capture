<?php

namespace Tests\Feature\Auth;

use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\CaptchaHitungService;
use App\Services\KioskService;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CAPTCHA hitungan pada layar masuk Panel Admin (FR-AUTH-03).
 *
 * Dua hal yang dikunci di sini, dan keduanya adalah syarat yang paling mudah
 * hilang tanpa ada yang menyadarinya:
 *
 *   1. CAPTCHA diminta sejak percobaan PERTAMA. Rancangan progresif — baru
 *      muncul setelah beberapa kegagalan — pernah dipakai dan dibatalkan;
 *      tanpa uji, "penyederhanaan" berikutnya mudah sekali mengembalikannya.
 *   2. Route kiosk TIDAK PERNAH menyentuhnya. Ini batas yang gampang runtuh
 *      justru ketika seseorang berniat baik: memasang pemeriksaan CAPTCHA di
 *      middleware global akan melumpuhkan seluruh perangkat absen di lapangan,
 *      dan kegagalannya baru ketahuan pada apel pagi berikutnya.
 */
class CaptchaLoginTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-perangkat-captcha';

    protected User $admin;

    protected UnitKerja $upt;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->travelTo('2026-09-07 07:35:00');

        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $this->upt = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $opd->id]);

        $this->admin = User::factory()->superadmin()->create([
            'email' => 'admin@capture.test',
            'password' => bcrypt('sandi-yang-benar'),
        ]);

        app(SettingAbsenService::class)->simpan(['metode_wajah_aktif' => false], $this->admin);
    }

    /** Buka layar masuk lalu kembalikan jawaban soal yang berlaku. */
    protected function soalBaru(): string
    {
        $this->get('/masuk');

        return (string) session(CaptchaHitungService::KUNCI_SESI);
    }

    /* ---------------------------------------------------------------------
     * Diminta sejak percobaan pertama
     * ------------------------------------------------------------------- */

    #[Test]
    public function layar_masuk_selalu_membawa_soal(): void
    {
        $this->get('/masuk')
            ->assertOk()
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->component('Auth/Masuk')
                ->whereNot('soal_captcha', null)
                ->etc());
    }

    #[Test]
    public function percobaan_pertama_tanpa_captcha_ditolak(): void
    {
        /*
         * Inti pembatalan rancangan progresif: bahkan dengan kata sandi yang
         * BENAR, percobaan pertama tanpa jawaban hitungan tidak boleh lolos.
         */
        $this->soalBaru();

        $this->post('/masuk', [
            'email' => 'admin@capture.test',
            'password' => 'sandi-yang-benar',
        ])->assertSessionHasErrors('jawaban_captcha');

        $this->assertGuest();
    }

    #[Test]
    public function percobaan_pertama_dengan_captcha_benar_diterima(): void
    {
        $this->post('/masuk', [
            'email' => 'admin@capture.test',
            'password' => 'sandi-yang-benar',
            'jawaban_captcha' => $this->soalBaru(),
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->admin);
    }

    #[Test]
    public function jawaban_salah_ditolak_walau_sandinya_benar(): void
    {
        $this->soalBaru();

        $this->post('/masuk', [
            'email' => 'admin@capture.test',
            'password' => 'sandi-yang-benar',
            'jawaban_captcha' => '9999',
        ])->assertSessionHasErrors('jawaban_captcha');

        $this->assertGuest();
    }

    /* ---------------------------------------------------------------------
     * Sekali pakai
     * ------------------------------------------------------------------- */

    #[Test]
    public function soal_hangus_setelah_satu_kali_diperiksa(): void
    {
        $jawaban = $this->soalBaru();

        $this->post('/masuk', [
            'email' => 'admin@capture.test',
            'password' => 'sandi-salah',
            'jawaban_captcha' => $jawaban,
        ]);

        $this->assertNull(
            session(CaptchaHitungService::KUNCI_SESI),
            'Soal harus hangus setelah diperiksa, benar maupun salah.',
        );
    }

    #[Test]
    public function jawaban_lama_tidak_dapat_diputar_ulang(): void
    {
        /*
         * Serangan yang paling murah: jawab satu soal dengan benar, lalu kirim
         * jawaban yang sama berulang kali bersama seluruh daftar kata sandi.
         */
        $jawaban = $this->soalBaru();

        $this->post('/masuk', [
            'email' => 'admin@capture.test',
            'password' => 'sandi-salah',
            'jawaban_captcha' => $jawaban,
        ]);

        $this->post('/masuk', [
            'email' => 'admin@capture.test',
            'password' => 'sandi-yang-benar',
            'jawaban_captcha' => $jawaban,
        ])->assertSessionHasErrors('jawaban_captcha');

        $this->assertGuest();
    }

    #[Test]
    public function setiap_kali_layar_digambar_soalnya_diganti(): void
    {
        $soal = [];

        for ($i = 0; $i < 12; $i++) {
            $this->get('/masuk');
            $soal[] = session(CaptchaHitungService::KUNCI_SOAL);
        }

        // Dua belas gambar ulang yang seluruhnya menghasilkan soal identik
        // berarti soalnya tidak pernah benar-benar dibuat ulang.
        $this->assertGreaterThan(
            1,
            count(array_unique($soal)),
            'Soal harus dibuat baru setiap kali formulirnya digambar.',
        );
    }

    #[Test]
    public function jawaban_tidak_pernah_ikut_dikirim_ke_klien(): void
    {
        /*
         * Jawabannya tinggal di sesi milik server. Field tersembunyi atau
         * atribut data yang memuatnya — sekalipun terenkode — membuat seluruh
         * penghalang ini dapat dilewati siapa pun yang membuka Inspect Element.
         *
         * Yang TIDAK diuji di sini: bahwa soalnya "tidak memuat jawabannya".
         * Jawaban sebuah soal hitungan memang selalu dapat diturunkan dari
         * soalnya — itulah hakikat tantangannya. Yang berarti hanyalah bahwa
         * jawabannya tidak ikut dikirim sebagai NILAI tersendiri.
         */
        $jawaban = $this->soalBaru();

        $layar = $this->get('/masuk');

        $layar->assertDontSee(CaptchaHitungService::KUNCI_SESI);

        $props = $layar->viewData('page')['props'];

        $this->assertSame(
            ['soal_captcha'],
            array_values(array_filter(
                array_keys($props),
                fn (string $kunci) => str_contains($kunci, 'captcha'),
            )),
            'Hanya soalnya yang boleh menyeberang ke klien.',
        );

        $this->assertNotSame((string) $jawaban, (string) $props['soal_captcha']);
    }

    /* ---------------------------------------------------------------------
     * Batas cakupan: kiosk tidak pernah menyentuh CAPTCHA
     * ------------------------------------------------------------------- */

    #[Test]
    public function route_kiosk_tidak_pernah_menyentuh_captcha(): void
    {
        /*
         * Batas ini paling mudah runtuh justru karena niat baik: memasang
         * pemeriksaan CAPTCHA di middleware global akan melumpuhkan seluruh
         * perangkat absen di lapangan, dan kegagalannya baru ketahuan pada
         * apel pagi berikutnya.
         *
         * Yang diperiksa bukan sekadar "tapnya berhasil", melainkan bahwa sesi
         * permintaan kiosk tidak pernah berisi soal CAPTCHA sama sekali —
         * artinya kodenya memang tidak pernah dilewati, bukan dilewati lalu
         * kebetulan lolos.
         */
        Kiosk::factory()->diaktifkan(self::TOKEN)->create(['unit_kerja_id' => $this->upt->id]);
        Pegawai::factory()->create(['nip' => '199001012020011001', 'unit_kerja_id' => $this->upt->id]);

        $jalur = [
            ['get', '/', []],
            ['get', '/kiosk/umum', []],
            ['post', '/kiosk/umum/absen', [
                'id_card' => '199001012020011001',
                'jenis' => 'datang',
                'metode' => 'manual',
            ]],
        ];

        foreach ($jalur as [$cara, $alamat, $muatan]) {
            $jawaban = $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
                ->{$cara}($alamat, $muatan, ['Accept' => 'application/json']);

            $this->assertNotSame(
                419,
                $jawaban->getStatusCode(),
                "{$alamat} tidak boleh tertahan pemeriksaan apa pun milik layar masuk.",
            );

            $this->assertNull(
                session(CaptchaHitungService::KUNCI_SESI),
                "{$alamat} tidak boleh pernah membuat soal CAPTCHA.",
            );

            $this->assertNull(
                session(CaptchaHitungService::KUNCI_SOAL),
                "{$alamat} tidak boleh pernah membuat soal CAPTCHA.",
            );
        }
    }

    #[Test]
    public function tap_kiosk_tetap_tercatat_tanpa_jawaban_hitungan(): void
    {
        Kiosk::factory()->diaktifkan(self::TOKEN)->create(['unit_kerja_id' => $this->upt->id]);
        Pegawai::factory()->create(['nip' => '199001012020011001', 'unit_kerja_id' => $this->upt->id]);

        $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->post('/kiosk/umum/absen', [
                'id_card' => '199001012020011001',
                'jenis' => 'datang',
                'metode' => 'manual',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    /* ---------------------------------------------------------------------
     * Ringan
     * ------------------------------------------------------------------- */

    #[Test]
    public function pembuatan_soal_tidak_memakai_proses_berat(): void
    {
        /*
         * Angka acak dan satu penulisan sesi — tanpa pustaka gambar, tanpa
         * berkas sementara, tanpa panggilan keluar. Ambangnya sengaja longgar
         * (25 ms untuk 50 soal) supaya uji ini tidak goyah di mesin yang
         * sedang sibuk; yang dijaga adalah selisih ordo besaran, bukan
         * milidetik.
         */
        $permintaan = Request::create('/masuk');
        $permintaan->setLaravelSession(app('session.store'));

        $mulai = microtime(true);

        for ($i = 0; $i < 50; $i++) {
            app(CaptchaHitungService::class)->buat($permintaan);
        }

        $lama = (microtime(true) - $mulai) * 1000;

        $this->assertLessThan(
            25,
            $lama,
            "Pembuatan 50 soal memakan {$lama} ms; itu pertanda ada proses berat yang masuk.",
        );
    }
}
