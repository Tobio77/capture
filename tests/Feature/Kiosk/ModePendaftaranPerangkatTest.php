<?php

namespace Tests\Feature\Kiosk;

use App\Enums\SumberKiosk;
use App\Models\Kiosk;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\KioskService;
use App\Services\SettingAbsenService;
use App\Support\PengaturanRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\KodeUnitKerjaTest;
use Tests\TestCase;

/**
 * Mode Pendaftaran Perangkat (FR-SET-06, revisi S49).
 *
 * Sakelar ini menentukan JALUR MASUK perangkat absen, dan kedua jalurnya tidak
 * pernah berlaku bersamaan:
 *
 *   - Mati (bawaan) → perangkat mengetikkan kode unit kerjanya, dan barisnya
 *     dibuat sendiri oleh sistem. Tidak ada yang perlu didaftarkan di muka,
 *     sebab jumlah komputer yang dipakai sebuah UPT berubah dari hari ke hari.
 *   - Menyala → perangkat harus sudah didaftarkan admin dan menukarkan kode
 *     aktivasi sekali pakai miliknya sendiri.
 *
 * Bawaannya sengaja DIBALIK dari sebelum S49, ketika sakelar yang sama bernama
 * "wajib kode aktivasi" dan menyala secara bawaan. Yang dulu terjadi bila ia
 * dimatikan adalah Mode Terbuka: mesin mana pun yang menjangkau alamat server
 * boleh masuk dengan memilih unit dari sebuah daftar. Jalur bawaan kini tetap
 * menuntut kode, sehingga pelonggaran itu — beserta peringatan yang
 * menyertainya — tidak ada lagi.
 *
 * Penukaran kode unitnya sendiri diuji di {@see KodeUnitKerjaTest}.
 */
class ModePendaftaranPerangkatTest extends TestCase
{
    use RefreshDatabase;

    protected UnitKerja $unitKerja;

    protected function setUp(): void
    {
        parent::setUp();

        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $this->unitKerja = UnitKerja::factory()->create([
            'kode' => 'BLK-SBY',
            'induk_id' => $opd->id,
        ]);
    }

    protected function nyalakanModePendaftaran(): void
    {
        app(PengaturanRepository::class)->simpan(
            SettingAbsenService::KUNCI_PENDAFTARAN_PERANGKAT,
            '1',
        );
    }

    /* ---------------------------------------------------------------------
     * Bawaan: mati, dan jalannya lewat kode unit kerja.
     * ------------------------------------------------------------------- */

    #[Test]
    public function mode_pendaftaran_mati_secara_bawaan(): void
    {
        // Instalasi baru tanpa satu pun baris pengaturan.
        $this->assertDatabaseCount('pengaturan', 0);

        $this->assertFalse(app(SettingAbsenService::class)->ambil()['pendaftaran_perangkat_aktif']);
        $this->assertFalse(app(SettingAbsenService::class)->pendaftaranPerangkatAktif());
    }

    #[Test]
    public function layar_masuk_meminta_kode_unit_kerja_secara_bawaan(): void
    {
        $this->get('/kiosk/aktivasi')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Kiosk/Aktivasi')
                ->where('mode_pendaftaran', false)

                /*
                 * Daftar unit kerja tidak pernah ikut pada kedua mode: mesin
                 * yang belum memegang kode tidak berkepentingan mengetahui
                 * unit mana saja yang ada, dan kodenyalah yang menentukan —
                 * bukan pilihan pada sebuah daftar.
                 */
                ->missing('unit_kerja')
                ->etc());
    }

    #[Test]
    public function perangkat_yang_masuk_lewat_kode_unit_ditandai_ad_hoc(): void
    {
        // Ia tidak pernah melewati peninjauan seorang admin, sehingga Daftar
        // Perangkat harus dapat membedakannya dari yang memang didaftarkan.
        $this->post('/kiosk/aktivasi/unit', [
            'kode' => $this->unitKerja->fresh()->kode_perangkat,
        ])
            ->assertRedirect('/')
            ->assertCookie(KioskService::NAMA_COOKIE);

        $perangkat = Kiosk::sole();

        $this->assertSame(SumberKiosk::AdHoc, $perangkat->sumber);
        $this->assertSame($this->unitKerja->id, $perangkat->unit_kerja_id);
        $this->assertSame('127.0.0.1', $perangkat->ip_terakhir);
        $this->assertNotNull($perangkat->diaktifkan_pada);
    }

    /* ---------------------------------------------------------------------
     * Saat dinyalakan: kembali ke jalur kode aktivasi.
     * ------------------------------------------------------------------- */

    #[Test]
    public function layar_masuk_meminta_kode_aktivasi_saat_mode_menyala(): void
    {
        $this->nyalakanModePendaftaran();

        $this->get('/kiosk/aktivasi')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('mode_pendaftaran', true)
                ->etc());
    }

    #[Test]
    public function perangkat_terdaftar_menukarkan_kode_aktivasinya(): void
    {
        $this->nyalakanModePendaftaran();

        Kiosk::factory()->menungguAktivasi('ABCD2345')->create([
            'nama_titik' => 'Aula Utama',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        $this->post('/kiosk/aktivasi', ['kode_aktivasi' => 'ABCD2345'])
            ->assertRedirect('/')
            ->assertCookie(KioskService::NAMA_COOKIE);

        $perangkat = Kiosk::sole();

        $this->assertSame(SumberKiosk::Terdaftar, $perangkat->sumber);
        $this->assertNotNull($perangkat->device_token);
        $this->assertNull($perangkat->kode_aktivasi, 'Kode aktivasi sekali pakai harus hangus.');
    }

    #[Test]
    public function kode_unit_tidak_menjadi_jalan_pintas_saat_mode_menyala(): void
    {
        /*
         * Pagar inilah alasan sakelar ini ada. Instansi yang menyalakannya
         * bermaksud mengunci daftar mesinnya; membiarkan kode unit tetap
         * diterima berarti sakelar itu tidak mengunci apa pun.
         */
        $this->nyalakanModePendaftaran();

        $this->post('/kiosk/aktivasi/unit', [
            'kode' => $this->unitKerja->fresh()->kode_perangkat,
        ])->assertForbidden();

        $this->assertDatabaseCount('kiosk', 0);
    }

    #[Test]
    public function perangkat_yang_sudah_dikenali_tidak_terputus_saat_mode_dinyalakan(): void
    {
        // Menyalakan mode ini di tengah hari kerja tidak boleh menghentikan
        // titik absen yang sedang melayani antrean.
        Kiosk::factory()->diaktifkan('token-lama')->create([
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        $this->nyalakanModePendaftaran();

        $this->withCookie(KioskService::NAMA_COOKIE, 'token-lama')
            ->get('/kiosk/umum')
            ->assertOk();
    }

    /* ---------------------------------------------------------------------
     * Daftar perangkat.
     * ------------------------------------------------------------------- */

    #[Test]
    public function daftar_perangkat_membedakan_ad_hoc_dari_terdaftar(): void
    {
        $this->post('/kiosk/aktivasi/unit', [
            'kode' => $this->unitKerja->fresh()->kode_perangkat,
        ]);

        Kiosk::factory()->create([
            'nama_titik' => 'Aula Utama',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/admin/perangkat')
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $baris = collect($page->toArray()['props']['daftar']['data']);

                $adHoc = $baris->firstWhere('sumber', 'ad_hoc');
                $terdaftar = $baris->firstWhere('sumber', 'terdaftar');

                $this->assertNotNull($adHoc, 'Perangkat ad-hoc tidak muncul pada daftar.');
                $this->assertSame('Ad-hoc', $adHoc['sumber_label']);
                $this->assertSame('Aula Utama', $terdaftar['nama_titik']);
            });
    }
}
