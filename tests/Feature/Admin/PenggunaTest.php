<?php

namespace Tests\Feature\Admin;

use App\Enums\AksiLog;
use App\Enums\PeranPengguna;
use App\Models\LogAktivitas;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kelola akun admin (FR-USR-01).
 */
class PenggunaTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = '/admin/pengguna';

    /**
     * @return array{upt: UnitKerja, seksi: UnitKerja}
     */
    protected function hirarki(): array
    {
        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $upt = UnitKerja::factory()->create(['kode' => 'BLK-SGS', 'induk_id' => $opd->id]);
        $seksi = UnitKerja::factory()->create(['kode' => 'BLK-SGS-TU', 'induk_id' => $upt->id]);

        return compact('upt', 'seksi');
    }

    /**
     * @param  array<string, mixed>  $ubahan
     * @return array<string, mixed>
     */
    protected function isian(array $ubahan = []): array
    {
        return array_merge([
            'nama' => 'Budi Santoso',
            'email' => 'budi@disnakertrans.jatimprov.go.id',
            'role' => 'admin_dinas',
            'unit_kerja_id' => null,
        ], $ubahan);
    }

    #[Test]
    public function superadmin_dapat_membuat_akun_admin(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian())
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $dibuat = User::query()->where('email', 'budi@disnakertrans.jatimprov.go.id')->sole();

        $this->assertSame(PeranPengguna::AdminDinas, $dibuat->role);
        $this->assertTrue($dibuat->aktif);
        $this->assertNull($dibuat->unit_kerja_id);
    }

    #[Test]
    public function kata_sandi_sementara_diterbitkan_dan_hanya_tampil_sekali(): void
    {
        $jawaban = $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian())
            ->assertSessionHas('sandi_sementara');

        $sandi = session('sandi_sementara')['sandi'];
        $dibuat = User::query()->where('email', 'budi@disnakertrans.jatimprov.go.id')->sole();

        // Yang tersimpan hanya hash-nya; sandi mentah tidak pernah ada di basis
        // data, sehingga hanya sempat terlihat sekali lewat flash.
        $this->assertTrue(Hash::check($sandi, $dibuat->password));
        $this->assertNotSame($sandi, $dibuat->password);

        $jawaban->assertRedirect();
    }

    #[Test]
    public function admin_upt_wajib_memiliki_unit_kerja(): void
    {
        $this->hirarki();

        // Tanpa unit, Admin UPT tidak punya cakupan sama sekali dan akan
        // melihat halaman kosong di mana-mana.
        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian(['role' => 'admin_upt', 'unit_kerja_id' => null]))
            ->assertSessionHasErrors('unit_kerja_id');
    }

    #[Test]
    public function cakupan_admin_harus_unit_level_teratas(): void
    {
        ['seksi' => $seksi] = $this->hirarki();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian(['role' => 'admin_upt', 'unit_kerja_id' => $seksi->id]))
            ->assertSessionHasErrors('unit_kerja_id');
    }

    #[Test]
    public function unit_kerja_diabaikan_pada_peran_lintas_unit(): void
    {
        ['upt' => $upt] = $this->hirarki();

        // Cakupan bayangan pada peran lintas unit hanya menyesatkan.
        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian(['role' => 'admin_dinas', 'unit_kerja_id' => $upt->id]))
            ->assertSessionHas('sukses');

        $this->assertNull(User::query()->where('email', $this->isian()['email'])->sole()->unit_kerja_id);
    }

    #[Test]
    public function alamat_surel_tidak_boleh_ganda(): void
    {
        User::factory()->create(['email' => 'budi@disnakertrans.jatimprov.go.id']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, $this->isian())
            ->assertSessionHasErrors('email');
    }

    #[Test]
    public function akun_dapat_diubah_beserta_cakupannya(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $sasaran = User::factory()->create(['role' => PeranPengguna::AdminDinas]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->patch(self::URL."/{$sasaran->id}", $this->isian([
                'nama' => 'Budi Diperbarui',
                'email' => $sasaran->email,
                'role' => 'admin_upt',
                'unit_kerja_id' => $upt->id,
            ]))
            ->assertSessionHas('sukses');

        $sasaran->refresh();

        $this->assertSame('Budi Diperbarui', $sasaran->nama);
        $this->assertSame(PeranPengguna::AdminUpt, $sasaran->role);
        $this->assertSame($upt->id, $sasaran->unit_kerja_id);
    }

    #[Test]
    public function akun_dinonaktifkan_bukan_dihapus(): void
    {
        $sasaran = User::factory()->create();

        $this->actingAs(User::factory()->superadmin()->create())
            ->patch(self::URL."/{$sasaran->id}/status", ['aktif' => false])
            ->assertSessionHas('sukses');

        $this->assertFalse($sasaran->refresh()->aktif);
        $this->assertDatabaseCount('users', 2);
    }

    #[Test]
    public function superadmin_tidak_dapat_menonaktifkan_akunnya_sendiri(): void
    {
        /*
         * Menonaktifkan diri sendiri langsung memutus akses lewat middleware
         * `pengguna.aktif`; bila ia satu-satunya Superadmin, tidak ada jalan
         * kembali dari dalam aplikasi.
         */
        $saya = User::factory()->superadmin()->create();

        $this->actingAs($saya)
            ->patch(self::URL."/{$saya->id}/status", ['aktif' => false])
            ->assertSessionHasErrors('aktif');

        $this->assertTrue($saya->refresh()->aktif);
    }

    #[Test]
    public function superadmin_tidak_dapat_menurunkan_perannya_sendiri(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $saya = User::factory()->superadmin()->create();

        $this->actingAs($saya)
            ->patch(self::URL."/{$saya->id}", $this->isian([
                'nama' => $saya->nama,
                'email' => $saya->email,
                'role' => 'admin_upt',
                'unit_kerja_id' => $upt->id,
            ]))
            ->assertSessionHasErrors('role');

        $this->assertSame(PeranPengguna::Superadmin, $saya->refresh()->role);
    }

    #[Test]
    public function reset_sandi_menerbitkan_sandi_baru_dan_menggugurkan_sesi(): void
    {
        $sasaran = User::factory()->create();
        $tokenLama = $sasaran->remember_token;
        $hashLama = $sasaran->password;

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL."/{$sasaran->id}/reset-sandi")
            ->assertSessionHas('sandi_sementara');

        $sasaran->refresh();

        $this->assertTrue(Hash::check(session('sandi_sementara')['sandi'], $sasaran->password));
        $this->assertNotSame($hashLama, $sasaran->password);

        // Sesi berjalan ikut gugur agar akun yang disalahgunakan benar-benar
        // terputus begitu sandinya diganti.
        $this->assertNotSame($tokenLama, $sasaran->remember_token);
    }

    #[Test]
    public function admin_dinas_tidak_dapat_menyentuh_akun_admin(): void
    {
        // Matriks peran SRS §6: Admin Dinas terbatas pada perangkat absen.
        $admin = User::factory()->create(['role' => PeranPengguna::AdminDinas]);

        $this->actingAs($admin)->get(self::URL)->assertForbidden();
        $this->actingAs($admin)->post(self::URL, $this->isian())->assertForbidden();
    }

    #[Test]
    public function admin_upt_tidak_dapat_menyentuh_akun_admin(): void
    {
        ['upt' => $upt] = $this->hirarki();

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL)
            ->assertForbidden();
    }

    #[Test]
    public function daftar_menampilkan_cakupan_setiap_akun(): void
    {
        ['upt' => $upt] = $this->hirarki();
        User::factory()->adminUpt($upt)->create(['nama' => 'Admin BLK Singosari']);

        $this->actingAs(User::factory()->superadmin()->create(['nama' => 'Superadmin']))
            ->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Pengguna/Index')
                ->has('daftar', 2)
                ->where('daftar.0.nama', 'Admin BLK Singosari')
                ->where('daftar.0.unit_kerja.kode', 'BLK-SGS')
                ->where('daftar.1.unit_kerja', null)
                ->etc());
    }

    #[Test]
    public function perubahan_akun_tercatat_pada_audit_trail(): void
    {
        $pelaku = User::factory()->superadmin()->create();

        $this->actingAs($pelaku)->post(self::URL, $this->isian());

        $log = LogAktivitas::aksi(AksiLog::Buat)->sole();
        $dibuat = User::query()->where('email', $this->isian()['email'])->sole();

        $this->assertSame($pelaku->id, $log->user_id);
        $this->assertTrue($log->subjek->is($dibuat));

        // Kata sandi tidak pernah ikut tercatat.
        $this->assertStringNotContainsString(session('sandi_sementara')['sandi'], $log->deskripsi);
    }
}
