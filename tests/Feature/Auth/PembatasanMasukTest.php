<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\AutentikasiService;
use App\Services\CaptchaHitungService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pembatasan laju bertingkat dan CAPTCHA progresif pada login admin
 * (FR-AUTH-01, FR-AUTH-03).
 *
 * Dua hal yang diuji di sini tidak dapat dilihat dari layar mana pun, dan
 * keduanya adalah tempat pertahanan ini paling mudah rusak tanpa disadari:
 *
 *   1. Penguncian NAIK BERTINGKAT. Penguncian tetap 60 detik selamanya —
 *      perilaku sebelum revisi ini — masih menyisakan 7.200 percobaan per hari
 *      bagi skrip yang dibiarkan berjalan semalaman. Yang dijaga bukan
 *      "ada penguncian", melainkan "penguncian kedua lebih lama".
 *   2. CAPTCHA TIDAK muncul di percobaan pertama. Ini syarat pengalaman
 *      pemakaian, dan syarat semacam itu biasanya yang pertama hilang ketika
 *      seseorang kelak menyederhanakan kodenya.
 */
class PembatasanMasukTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->admin = User::factory()->superadmin()->create([
            'email' => 'admin@capture.test',
            'password' => bcrypt('sandi-yang-benar'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $tambahan
     */
    protected function coba(string $sandi = 'sandi-salah', array $tambahan = [])
    {
        return $this->post('/masuk', [
            'email' => 'admin@capture.test',
            'password' => $sandi,
            ...$tambahan,
        ]);
    }

    protected function gagalSebanyak(int $kali): void
    {
        for ($i = 0; $i < $kali; $i++) {
            $this->coba(tambahan: ['jawaban_captcha' => $this->jawabanBenar()]);
        }
    }

    /**
     * Jawaban soal yang berlaku, dengan lebih dahulu MEMBUKA layar masuknya.
     *
     * Membuka layarnya bukan kerapian uji, melainkan alur yang sebenarnya:
     * soal dibuat ketika halaman digambar, dan jawabannya tidak pernah
     * dikirim ke klien — ia tinggal di sesi milik server. Uji yang membaca
     * sesi tanpa membuka halamannya akan menguji keadaan yang tidak pernah
     * dialami siapa pun.
     */
    protected function jawabanBenar(): ?string
    {
        $this->get('/masuk');

        $jawaban = session(CaptchaHitungService::KUNCI_SESI);

        return $jawaban === null ? null : (string) $jawaban;
    }

    /* ---------------------------------------------------------------------
     * Pembatasan laju
     * ------------------------------------------------------------------- */

    #[Test]
    public function login_benar_tetap_berhasil_tanpa_hambatan(): void
    {
        $this->coba('sandi-yang-benar')->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->admin);
    }

    #[Test]
    public function percobaan_gagal_dikunci_setelah_menyentuh_batas(): void
    {
        $this->gagalSebanyak(AutentikasiService::BATAS_PERCOBAAN);

        $this->coba('sandi-yang-benar')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function penguncian_kedua_lebih_lama_daripada_yang_pertama(): void
    {
        /*
         * Inti revisi ini. Sebelumnya kedua penguncian sama-sama 60 detik,
         * sehingga penyerang yang sabar hanya membayar satu menit per lima
         * percobaan — selamanya.
         */
        $this->gagalSebanyak(AutentikasiService::BATAS_PERCOBAAN);
        $pertama = $this->sisaKunci();

        $this->travel(61)->seconds();

        $this->gagalSebanyak(AutentikasiService::BATAS_PERCOBAAN);
        $kedua = $this->sisaKunci();

        $this->assertNotNull($pertama, 'Penguncian pertama tidak terpasang.');
        $this->assertNotNull($kedua, 'Penguncian kedua tidak terpasang.');
        $this->assertGreaterThan(
            $pertama,
            $kedua,
            'Penguncian kedua harus lebih lama; penguncian tetap tidak menghentikan siapa pun.',
        );
    }

    #[Test]
    public function login_berhasil_membersihkan_hitungan_kegagalan(): void
    {
        // Salah ketik sekali pagi ini tidak boleh menghukum orang yang sama
        // besok — hitungannya nol lagi begitu ia berhasil masuk.
        $this->gagalSebanyak(AutentikasiService::AMBANG_CAPTCHA);

        $this->assertTrue($this->perluCaptcha());

        $this->coba('sandi-yang-benar', ['jawaban_captcha' => $this->jawabanBenar()])
            ->assertRedirect(route('dashboard'));

        $this->assertFalse($this->perluCaptcha());
    }

    #[Test]
    public function mengganti_surel_tidak_memberi_jatah_baru(): void
    {
        /*
         * Daftar surel dinas mudah ditebak dari pola namanya. Tanpa penghitung
         * kedua per alamat IP, penyerang cukup berganti surel setiap lima
         * percobaan untuk memperoleh jatah baru tanpa henti.
         */
        for ($i = 0; $i < AutentikasiService::BATAS_PERCOBAAN; $i++) {
            $this->post('/masuk', [
                'email' => "orang{$i}@capture.test",
                'password' => 'sandi-salah',
            ]);
        }

        $this->coba('sandi-yang-benar')->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /* ---------------------------------------------------------------------
     * CAPTCHA progresif
     * ------------------------------------------------------------------- */

    #[Test]
    public function captcha_tidak_muncul_pada_percobaan_pertama(): void
    {
        $this->get('/masuk')
            ->assertOk()
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->component('Auth/Masuk')
                ->where('perlu_captcha', false)
                ->where('soal_captcha', null)
                ->etc());
    }

    #[Test]
    public function captcha_muncul_setelah_beberapa_kali_gagal(): void
    {
        $this->gagalSebanyak(AutentikasiService::AMBANG_CAPTCHA);

        $this->get('/masuk')
            ->assertOk()
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->where('perlu_captcha', true)
                ->whereNot('soal_captcha', null)
                ->etc());
    }

    #[Test]
    public function jawaban_captcha_yang_salah_menolak_walau_sandinya_benar(): void
    {
        $this->gagalSebanyak(AutentikasiService::AMBANG_CAPTCHA);

        $this->coba('sandi-yang-benar', ['jawaban_captcha' => '9999'])
            ->assertSessionHasErrors('jawaban_captcha');

        $this->assertGuest();
    }

    #[Test]
    public function soal_hangus_sekali_pakai(): void
    {
        /*
         * Soal yang tidak dihanguskan setelah dijawab benar membuat satu
         * jawaban dapat dipakai berulang untuk seluruh daftar kata sandi —
         * persis yang hendak dicegah.
         */
        $this->gagalSebanyak(AutentikasiService::AMBANG_CAPTCHA);

        $this->coba('sandi-salah', ['jawaban_captcha' => $this->jawabanBenar()]);

        /*
         * Yang diperiksa: soalnya HANGUS, bukan bahwa jawaban berikutnya
         * berbeda angka. Jawabannya berkisar 1–18, sehingga soal baru cukup
         * sering kebetulan berjawaban sama — uji yang membandingkan angkanya
         * akan merah sesekali tanpa ada yang rusak.
         */
        $this->assertNull(
            session(CaptchaHitungService::KUNCI_SESI),
            'Soal harus dihanguskan setiap kali dijawab, benar maupun salah — '
            .'satu jawaban benar yang dapat dipakai berulang membatalkan gunanya.',
        );
    }

    #[Test]
    public function jawaban_captcha_yang_benar_meloloskan_login_yang_sah(): void
    {
        $this->gagalSebanyak(AutentikasiService::AMBANG_CAPTCHA);

        $this->coba('sandi-yang-benar', ['jawaban_captcha' => $this->jawabanBenar()])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->admin);
    }

    /* ---------------------------------------------------------------------
     * Bantuan
     * ------------------------------------------------------------------- */

    protected function perluCaptcha(): bool
    {
        return app(AutentikasiService::class)->perluCaptchaUntuk(request(), 'admin@capture.test');
    }

    /**
     * Sisa detik penguncian yang sedang berlaku, atau null bila tidak terkunci.
     */
    protected function sisaKunci(): ?int
    {
        $sampai = Cache::get('kunci-masuk:admin@capture.test|127.0.0.1');

        return $sampai === null ? null : (int) ceil(now()->diffInSeconds($sampai, false));
    }
}
