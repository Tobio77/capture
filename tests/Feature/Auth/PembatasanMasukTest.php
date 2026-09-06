<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\AutentikasiService;
use App\Services\CaptchaHitungService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pembatasan laju bertingkat pada login admin (FR-AUTH-01).
 *
 * Yang diuji di sini tidak dapat dilihat dari layar mana pun, dan justru itu
 * tempat pertahanan ini paling mudah rusak tanpa disadari: **penguncian NAIK
 * BERTINGKAT**. Penguncian tetap 60 detik selamanya — perilaku sebelum revisi
 * ini — masih menyisakan 7.200 percobaan per hari bagi skrip yang dibiarkan
 * berjalan semalaman. Yang dijaga bukan "ada penguncian", melainkan
 * "penguncian kedua lebih lama".
 *
 * Seluruh percobaan di sini ikut membawa jawaban hitungan, karena CAPTCHA kini
 * diminta sejak percobaan pertama; tanpa itu yang teruji hanyalah penolakan
 * CAPTCHA, bukan pembatasan lajunya. Perilaku CAPTCHA-nya sendiri diuji
 * terpisah di {@see CaptchaLoginTest}.
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
        $this->coba('sandi-yang-benar', ['jawaban_captcha' => $this->jawabanBenar()])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->admin);
    }

    #[Test]
    public function percobaan_gagal_dikunci_setelah_menyentuh_batas(): void
    {
        $this->gagalSebanyak(AutentikasiService::BATAS_PERCOBAAN);

        $this->coba('sandi-yang-benar', ['jawaban_captcha' => $this->jawabanBenar()])
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
        $this->gagalSebanyak(AutentikasiService::BATAS_PERCOBAAN - 1);

        $this->coba('sandi-yang-benar', ['jawaban_captcha' => $this->jawabanBenar()])
            ->assertRedirect(route('dashboard'));

        // Empat kegagalan tadi terhapus, sehingga lima kegagalan berikutnya
        // masih harus dijalani penuh sebelum penguncian berlaku lagi.
        $this->gagalSebanyak(AutentikasiService::BATAS_PERCOBAAN - 1);

        $this->coba('sandi-yang-benar', ['jawaban_captcha' => $this->jawabanBenar()])
            ->assertRedirect(route('dashboard'));
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
                'jawaban_captcha' => $this->jawabanBenar(),
            ]);
        }

        $this->coba('sandi-yang-benar', ['jawaban_captcha' => $this->jawabanBenar()])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /* ---------------------------------------------------------------------
     * Bantuan
     * ------------------------------------------------------------------- */

    /**
     * Sisa detik penguncian yang sedang berlaku, atau null bila tidak terkunci.
     */
    protected function sisaKunci(): ?int
    {
        $sampai = Cache::get('kunci-masuk:admin@capture.test|127.0.0.1');

        return $sampai === null ? null : (int) ceil(now()->diffInSeconds($sampai, false));
    }
}
