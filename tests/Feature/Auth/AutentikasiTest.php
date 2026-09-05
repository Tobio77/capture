<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\AutentikasiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutentikasiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function halaman_masuk_dapat_diakses_tamu(): void
    {
        $this->get('/masuk')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Masuk'));
    }

    #[Test]
    public function pengguna_aktif_dapat_masuk(): void
    {
        $pengguna = User::factory()->create([
            'email' => 'admin.dinas@capture.test',
            'password' => Hash::make('rahasia123'),
        ]);

        $this->post('/masuk', [
            'email' => 'admin.dinas@capture.test',
            'password' => 'rahasia123',
        ])->assertRedirect('/admin/dashboard');

        $this->assertAuthenticatedAs($pengguna);
    }

    #[Test]
    public function kata_sandi_salah_ditolak(): void
    {
        User::factory()->create([
            'email' => 'admin.dinas@capture.test',
            'password' => Hash::make('rahasia123'),
        ]);

        $this->post('/masuk', [
            'email' => 'admin.dinas@capture.test',
            'password' => 'salah',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function akun_nonaktif_tidak_dapat_masuk(): void
    {
        User::factory()->nonaktif()->create([
            'email' => 'mantan.admin@capture.test',
            'password' => Hash::make('rahasia123'),
        ]);

        $this->post('/masuk', [
            'email' => 'mantan.admin@capture.test',
            'password' => 'rahasia123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function sesi_diputus_bila_akun_dinonaktifkan_setelah_masuk(): void
    {
        $pengguna = User::factory()->create();

        $this->actingAs($pengguna)->get('/admin/dashboard')->assertOk();

        $pengguna->update(['aktif' => false]);

        $this->actingAs($pengguna)
            ->get('/admin/dashboard')
            ->assertRedirect('/masuk')
            ->assertSessionHas('gagal');

        $this->assertGuest();
    }

    #[Test]
    public function pengguna_dapat_keluar(): void
    {
        $pengguna = User::factory()->create();

        $this->actingAs($pengguna)
            ->post('/keluar')
            ->assertRedirect('/masuk');

        $this->assertGuest();
    }

    #[Test]
    public function percobaan_masuk_dibatasi_setelah_beberapa_kegagalan(): void
    {
        User::factory()->create([
            'email' => 'admin.dinas@capture.test',
            'password' => Hash::make('rahasia123'),
        ]);

        for ($percobaan = 0; $percobaan < AutentikasiService::BATAS_PERCOBAAN; $percobaan++) {
            $this->post('/masuk', [
                'email' => 'admin.dinas@capture.test',
                'password' => 'salah',
            ]);
        }

        // Kredensial benar pun ditolak selama masa penguncian.
        $respons = $this->post('/masuk', [
            'email' => 'admin.dinas@capture.test',
            'password' => 'rahasia123',
        ]);

        $respons->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Terlalu banyak percobaan masuk',
            session('errors')->first('email'),
        );
        $this->assertGuest();
    }

    #[Test]
    public function tamu_diarahkan_ke_halaman_masuk(): void
    {
        $this->get('/admin/dashboard')->assertRedirect('/masuk');

        /*
         * Halaman depan justru TIDAK boleh ikut dipagari (S30): ia melayani
         * pegawai dan petugas titik absen, bukan hanya admin. Yang dipagari
         * adalah panelnya.
         */
        $this->get('/')->assertOk();
    }

    #[Test]
    public function pengguna_yang_sudah_masuk_diarahkan_dari_halaman_masuk(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/masuk')
            ->assertRedirect('/admin/dashboard');
    }
}
