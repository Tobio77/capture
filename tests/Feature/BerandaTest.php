<?php

namespace Tests\Feature;

use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\AbsenUmumService;
use App\Services\KioskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Halaman depan aplikasi (S30).
 *
 * Satu pintu masuk untuk tiga orang yang berbeda — pegawai yang mengabsen
 * harian, petugas yang membuka titik absen kegiatan, dan admin yang menuju
 * panel — sehingga yang perlu dijaga di sini adalah ketiganya menemukan
 * jalannya, dan tidak ada yang melihat lebih daripada haknya.
 *
 * Sejak S49 tidak ada lagi kolom kode di halaman ini: kode menempel pada unit
 * kerja dan sudah diketikkan sekali di layar masuk perangkat, sehingga
 * perangkat yang dikenali langsung melayani kegiatan yang sedang dibuka.
 * Yang tersisa hanyalah dua kemungkinan — ada kegiatan, atau tidak.
 */
class BerandaTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-perangkat-uji';

    protected UnitKerja $upt;

    protected function setUp(): void
    {
        parent::setUp();

        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $this->upt = UnitKerja::factory()->create([
            'kode' => 'BLK-SBY',
            'nama' => 'UPT BLK Surabaya',
            'induk_id' => $opd->id,
        ]);
    }

    protected function perangkat(): Kiosk
    {
        return Kiosk::factory()->diaktifkan(self::TOKEN)->create([
            'nama_titik' => 'Aula Senam BLK Surabaya',
            'unit_kerja_id' => $this->upt->id,
        ]);
    }

    #[Test]
    public function terbuka_tanpa_autentikasi_apa_pun(): void
    {
        /*
         * Inti halaman ini. Mesin yang belum pernah dikenali harus dapat
         * membukanya — kalau tidak, petugas tidak punya tempat untuk memulai.
         */
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Beranda')
                ->where('perangkat', null)
                ->etc());
    }

    #[Test]
    public function perangkat_yang_belum_dikenali_tidak_melihat_kegiatan_yang_dibuka(): void
    {
        /*
         * Nama kegiatan adalah keterangan internal, dan tidak ada alasan
         * membocorkannya kepada mesin mana pun yang kebetulan dapat
         * menjangkau alamat server.
         */
        EventAbsen::factory()->create(['nama' => 'Apel Pagi']);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('event_aktif', null)->etc());
    }

    #[Test]
    public function perangkat_dikenali_melihat_dirinya_dan_kegiatan_yang_dibuka(): void
    {
        $this->perangkat();
        EventAbsen::factory()->create(['nama' => 'Apel Pagi Senin']);

        $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('perangkat.nama_titik', 'Aula Senam BLK Surabaya')
                /*
                 * Nama, bukan kode. Kode unit adalah penanda internal untuk
                 * admin; bagi orang yang berdiri di depan layar titik absen
                 * ia hanya deretan huruf, dan tidak ada tindakan di layar itu
                 * yang bergantung padanya.
                 */
                ->where('perangkat.unit_kerja.nama', 'UPT BLK Surabaya')
                ->missing('perangkat.unit_kerja.kode')
                ->where('event_aktif.nama', 'Apel Pagi Senin')
                ->etc());
    }

    #[Test]
    public function kode_unit_kerja_tidak_pernah_ikut_pada_halaman_depan(): void
    {
        // Kode adalah kunci masuk perangkat; halaman yang terbuka untuk siapa
        // pun bukan tempatnya dibacakan.
        $this->perangkat();
        EventAbsen::factory()->create();

        $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->get('/')
            ->assertOk()
            ->assertDontSee($this->upt->fresh()->kode_perangkat)
            ->assertInertia(fn (Assert $page) => $page
                ->has('event_aktif', fn (Assert $satu) => $satu
                    ->hasAll(['id', 'nama', 'tanggal', 'jam_mulai', 'toleransi_menit']))
                ->etc());
    }

    #[Test]
    public function perangkat_dikenali_tidak_perlu_bergabung_lebih_dahulu(): void
    {
        /*
         * Inti perubahan S49. Sampai S48, membuka layar Absen Event menuntut
         * penukaran kode per event lebih dulu — perangkat yang belum
         * bergabung dipulangkan ke beranda. Kini ia langsung masuk.
         */
        $this->perangkat();
        EventAbsen::factory()->create(['nama' => 'Rapat Koordinasi']);

        $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->get('/kiosk/event')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('event.nama', 'Rapat Koordinasi')
                ->etc());
    }

    #[Test]
    public function tanpa_kegiatan_layar_absen_event_dipulangkan_ke_beranda(): void
    {
        $this->perangkat();

        $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->get('/kiosk/event')
            ->assertRedirect('/')
            ->assertSessionHas('gagal');
    }

    #[Test]
    public function event_yang_sudah_ditutup_tidak_ikut_ditawarkan(): void
    {
        $this->perangkat();
        EventAbsen::factory()->ditutup()->create(['nama' => 'Apel Kemarin']);

        $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('event_aktif', null)->etc());
    }

    #[Test]
    public function sesi_absen_umum_harian_tidak_muncul_sebagai_kegiatan(): void
    {
        /*
         * Sesi harian dibuka sistem, bukan admin, dan punya pintunya sendiri
         * pada kartu Absen Umum. Membiarkannya masuk akan membuat kartu Absen
         * Event menawarkan sesuatu yang bukan kegiatan.
         */
        $this->perangkat();
        app(AbsenUmumService::class)->buka();

        $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('event_aktif', null)->etc());
    }

    #[Test]
    public function admin_yang_sudah_masuk_tetap_dapat_membuka_halaman_depan(): void
    {
        // Halaman depan bukan pengganti panel; ia hanya tidak menghalangi.
        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Beranda')
                ->where('auth.pengguna.role', 'superadmin')
                ->etc());
    }

    #[Test]
    public function alamat_lama_beranda_perangkat_dialihkan_ke_halaman_depan(): void
    {
        // Perangkat yang telanjur menyimpan alamat /kiosk tetap sampai.
        $this->get('/kiosk')->assertRedirect('/');
    }
}
