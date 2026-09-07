<?php

namespace Tests\Feature\Admin;

use App\Models\HariLibur;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pengelolaan hari libur bertanggal (FR-SET-08).
 *
 * Yang dijaga terutama CAKUPANNYA. Satu orang yang keliru menandai hari kerja
 * sebagai libur nasional akan menandai seluruh absensi hari itu di seluruh
 * provinsi — dan kekeliruan semacam itu baru ketahuan berminggu-minggu
 * kemudian, saat laporan bulanan disusun.
 *
 * Pemeriksaannya ada di server, bukan di layar: layar hanya menyembunyikan
 * pilihannya, dan yang disembunyikan masih dapat dikirim.
 */
class HariLiburTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = '/admin/kelola-absen/setting/hari-libur';

    protected UnitKerja $opd;

    protected UnitKerja $upt;

    protected UnitKerja $lain;

    protected User $superadmin;

    protected User $adminUpt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-07 08:00:00');

        $this->opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $this->upt = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $this->opd->id]);
        $this->lain = UnitKerja::factory()->create(['kode' => 'BLK-MLG', 'induk_id' => $this->opd->id]);

        $this->superadmin = User::factory()->superadmin()->create();
        $this->adminUpt = User::factory()->adminUpt($this->upt)->create();
    }

    #[Test]
    public function superadmin_dapat_menambah_libur_nasional(): void
    {
        $this->actingAs($this->superadmin)
            ->post(self::URL, [
                'tanggal' => '2026-12-25',
                'keterangan' => 'Hari Raya Natal',
            ])
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('hari_libur', [
            'keterangan' => 'Hari Raya Natal',
            'unit_kerja_id' => null,
        ]);
    }

    #[Test]
    public function admin_upt_tidak_dapat_menambah_libur_nasional(): void
    {
        /*
         * Batas yang paling penting di berkas ini. Libur nasional menyentuh
         * seluruh dinas, dan tidak seorang pun yang cakupannya satu UPT boleh
         * memasangnya.
         */
        $this->actingAs($this->adminUpt)
            ->post(self::URL, [
                'tanggal' => '2026-12-25',
                'keterangan' => 'Hari Raya Natal',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('hari_libur', 0);
    }

    #[Test]
    public function admin_upt_tidak_dapat_menyentuh_kalender_sama_sekali(): void
    {
        /*
         * Seluruh Setting Absen memang tertutup bagi Admin UPT — pengaturannya
         * berlaku global. Kalender libur ikut di dalamnya, dan itu keputusan
         * yang disengaja: libur satu UPT tetap menandai absensi seluruh
         * pegawainya, dan cakupan sebesar itu ditetapkan tingkat dinas.
         *
         * Pemeriksaan cakupan di dalam controller karena itu bukan jalur yang
         * dipakai sehari-hari melainkan lapis kedua: kalau kelak rute ini
         * dibuka bagi Admin UPT, batasnya sudah berdiri.
         */
        $this->actingAs($this->adminUpt)
            ->get('/admin/kelola-absen/setting')
            ->assertForbidden();

        $this->actingAs($this->adminUpt)
            ->post(self::URL, [
                'tanggal' => '2026-10-01',
                'keterangan' => 'HUT UPT',
                'unit_kerja_id' => $this->upt->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('hari_libur', 0);
    }

    #[Test]
    public function admin_upt_tidak_dapat_menambah_libur_unit_lain(): void
    {
        $this->actingAs($this->adminUpt)
            ->post(self::URL, [
                'tanggal' => '2026-10-01',
                'keterangan' => 'HUT UPT tetangga',
                'unit_kerja_id' => $this->lain->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('hari_libur', 0);
    }

    #[Test]
    public function tanggal_kembar_pada_cakupan_yang_sama_ditolak(): void
    {
        /*
         * Ditolak dengan pesan, bukan dibiarkan meledak sebagai galat kunci
         * unik: dua admin yang menambahkan "Idulfitri" pada hari yang sama
         * adalah kejadian biasa, bukan kesalahan sistem.
         */
        HariLibur::query()->create(['tanggal' => '2026-12-25', 'keterangan' => 'Natal']);

        $this->actingAs($this->superadmin)
            ->post(self::URL, ['tanggal' => '2026-12-25', 'keterangan' => 'Natal (lagi)'])
            ->assertSessionHasErrors('tanggal');

        $this->assertDatabaseCount('hari_libur', 1);
    }

    #[Test]
    public function tanggal_sama_untuk_cakupan_berbeda_tetap_boleh(): void
    {
        HariLibur::query()->create(['tanggal' => '2026-12-25', 'keterangan' => 'Natal']);

        $this->actingAs($this->superadmin)
            ->post(self::URL, [
                'tanggal' => '2026-12-25',
                'keterangan' => 'Kegiatan internal UPT',
                'unit_kerja_id' => $this->upt->id,
            ])
            ->assertSessionHas('sukses');

        $this->assertDatabaseCount('hari_libur', 2);
    }

    #[Test]
    public function layar_setting_membawa_daftar_libur(): void
    {
        HariLibur::query()->create(['tanggal' => '2026-12-25', 'keterangan' => 'Natal']);

        $this->actingAs($this->superadmin)
            ->get('/admin/kelola-absen/setting')
            ->assertOk()
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->has('hari_libur', 1)
                ->where('hari_libur.0.keterangan', 'Natal')
                ->where('hari_libur.0.nasional', true)
                ->where('boleh_libur_nasional', true)
                ->etc());
    }

    #[Test]
    public function admin_dinas_ditawari_pilihan_nasional(): void
    {
        $adminDinas = User::factory()->adminDinas()->create();

        $this->actingAs($adminDinas)
            ->get('/admin/kelola-absen/setting')
            ->assertOk()
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->where('boleh_libur_nasional', true)
                ->has('unit_kerja_libur')
                ->etc());
    }

    #[Test]
    public function libur_dapat_dihapus(): void
    {
        $libur = HariLibur::query()->create(['tanggal' => '2026-12-25', 'keterangan' => 'Natal']);

        $this->actingAs($this->superadmin)
            ->delete(self::URL."/{$libur->id}")
            ->assertSessionHas('sukses');

        $this->assertDatabaseCount('hari_libur', 0);
    }

    #[Test]
    public function admin_upt_tidak_dapat_menghapus_libur_nasional(): void
    {
        $libur = HariLibur::query()->create(['tanggal' => '2026-12-25', 'keterangan' => 'Natal']);

        $this->actingAs($this->adminUpt)
            ->delete(self::URL."/{$libur->id}")
            ->assertForbidden();

        $this->assertDatabaseCount('hari_libur', 1);
    }
}
