<?php

namespace Tests\Feature\Auth;

use App\Enums\PeranPengguna;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Menegakkan matriks peran vs hak akses (docs/02-SRS-Absensi.md §6, FR-AUTH-02).
 */
class AksesPeranTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Route yang tertutup bagi Admin UPT.
     *
     * @return array<string, array{string}>
     */
    public static function ruteKhususLintasUnit(): array
    {
        return [
            'Setting Absen' => ['/kelola-absen/setting'],
            'Kelola User / Role' => ['/pengguna'],
        ];
    }

    #[Test]
    #[DataProvider('ruteKhususLintasUnit')]
    public function admin_upt_ditolak_pada_menu_lintas_unit(string $rute): void
    {
        $this->actingAs($this->adminUpt())
            ->get($rute)
            ->assertForbidden();
    }

    #[Test]
    #[DataProvider('ruteKhususLintasUnit')]
    public function superadmin_dan_admin_dinas_dapat_mengakses_menu_lintas_unit(string $rute): void
    {
        $this->actingAs(User::factory()->superadmin()->create())->get($rute)->assertOk();

        $this->actingAs(User::factory()->create(['role' => PeranPengguna::AdminDinas]))
            ->get($rute)
            ->assertOk();
    }

    #[Test]
    public function semua_peran_dapat_mengakses_menu_umum(): void
    {
        $ruteUmum = ['/dashboard', '/kelola-absen/event', '/kelola-absen/rekap', '/kelola-absen/unit-kerja', '/pegawai', '/laporan'];

        foreach ([$this->adminUpt(), User::factory()->superadmin()->create()] as $pengguna) {
            foreach ($ruteUmum as $rute) {
                $this->actingAs($pengguna)->get($rute)->assertOk();
            }
        }
    }

    #[Test]
    public function menu_admin_upt_tidak_memuat_kelola_user_dan_setting_absen(): void
    {
        $this->actingAs($this->adminUpt())
            ->get('/dashboard')
            ->assertInertia(function (Assert $page) {
                $menu = $page->toArray()['props']['menu'];
                $label = $this->labelMenu($menu);

                $this->assertContains('Dashboard', $label);
                $this->assertContains('Daftar Event', $label);
                $this->assertContains('Setting Unit Kerja', $label);
                $this->assertNotContains('Setting Absen', $label);
                $this->assertNotContains('Kelola User / Role', $label);
            });
    }

    #[Test]
    public function menu_superadmin_memuat_seluruh_menu(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/dashboard')
            ->assertInertia(function (Assert $page) {
                $label = $this->labelMenu($page->toArray()['props']['menu']);

                $this->assertContains('Setting Absen', $label);
                $this->assertContains('Kelola User / Role', $label);
                $this->assertContains('Laporan', $label);
            });
    }

    #[Test]
    public function cakupan_unit_kerja_dibagikan_ke_frontend(): void
    {
        $adminUpt = $this->adminUpt();

        $this->actingAs($adminUpt)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.pengguna.role', 'admin_upt')
                ->where('auth.pengguna.lintas_unit', false)
                ->where('auth.pengguna.unit_kerja.kode', 'BLK-SBY')
                ->etc());

        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.pengguna.lintas_unit', true)
                ->where('auth.pengguna.unit_kerja', null)
                ->etc());
    }

    protected function adminUpt(): User
    {
        $unitKerja = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);

        return User::factory()->adminUpt($unitKerja)->create();
    }

    /**
     * @param  array<int, array<string, mixed>>  $menu
     * @return array<int, string>
     */
    protected function labelMenu(array $menu): array
    {
        $label = [];

        foreach ($menu as $item) {
            $label[] = $item['label'];

            foreach ($item['anak'] ?? [] as $anak) {
                $label[] = $anak['label'];
            }
        }

        return $label;
    }
}
