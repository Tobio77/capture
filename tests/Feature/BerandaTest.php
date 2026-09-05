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
        $this->upt = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $opd->id]);
    }

    protected function perangkat(): Kiosk
    {
        return Kiosk::factory()->diaktifkan(self::TOKEN)->create([
            'nama_titik' => 'Aula Senam BLK Surabaya',
            'unit_kerja_id' => $this->upt->id,
        ]);
    }

    protected function eventAktif(string $nama = 'Apel Pagi'): EventAbsen
    {
        $event = EventAbsen::factory()->create(['nama' => $nama]);
        $event->unitKerja()->attach($this->upt);

        return $event;
    }

    #[Test]
    public function terbuka_tanpa_autentikasi_apa_pun(): void
    {
        /*
         * Inti halaman ini. Mesin yang belum pernah diaktifkan harus dapat
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
    public function perangkat_yang_belum_aktif_tidak_melihat_daftar_event(): void
    {
        /*
         * Mengikuti keputusan yang sama pada layar aktivasi: nama kegiatan
         * beserta unit penyelenggaranya adalah keterangan internal. Kodenya
         * tetap penentu, tetapi daftar ini mempersempit tebakan — jadi ia ikut
         * dipagari.
         */
        $this->eventAktif();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('event_aktif', 0)->etc());
    }

    #[Test]
    public function perangkat_aktif_melihat_dirinya_dan_daftar_event(): void
    {
        $this->perangkat();
        $this->eventAktif('Apel Pagi Senin');

        $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('perangkat.nama_titik', 'Aula Senam BLK Surabaya')
                ->where('perangkat.unit_kerja.kode', 'BLK-SBY')
                ->has('event_aktif', 1)
                ->where('event_aktif.0.nama', 'Apel Pagi Senin')
                ->where('event_aktif.0.cakupan_label', 'BLK-SBY')

                // Belum bergabung: kartunya menawarkan kolom kode, bukan
                // pintasan ke layar tap.
                ->where('event_diikuti', null)
                ->etc());
    }

    #[Test]
    public function kode_event_tidak_pernah_ikut_pada_daftar(): void
    {
        // Yang membedakan petugas yang berhak dari yang tidak justru kodenya.
        $this->perangkat();
        $event = $this->eventAktif();

        $kode = $event->kodeUnit()->first()?->kode;

        $respons = $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)->get('/');

        $respons->assertOk();

        if ($kode !== null) {
            $respons->assertDontSee($kode);
        }

        $respons->assertInertia(fn (Assert $page) => $page
            ->has('event_aktif.0', fn (Assert $satu) => $satu
                ->hasAll(['id', 'nama', 'tanggal', 'jam_mulai', 'cakupan_label']))
            ->etc());
    }

    #[Test]
    public function perangkat_yang_sudah_bergabung_langsung_ditawari_layar_eventnya(): void
    {
        $perangkat = $this->perangkat();
        $event = $this->eventAktif('Rapat Koordinasi');

        $this->gabungkanKeEvent($event, $perangkat);

        $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('event_diikuti.nama', 'Rapat Koordinasi')
                ->etc());
    }

    #[Test]
    public function event_yang_sudah_ditutup_tidak_ikut_ditawarkan(): void
    {
        $this->perangkat();
        EventAbsen::factory()->ditutup()->create(['nama' => 'Apel Kemarin'])
            ->unitKerja()->attach($this->upt);

        $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page->has('event_aktif', 0)->etc());
    }

    #[Test]
    public function sesi_absen_umum_harian_tidak_muncul_sebagai_event(): void
    {
        /*
         * Sesi harian dibuka sistem, bukan admin, dan punya pintunya sendiri
         * pada kartu Absen Umum. Membiarkannya masuk daftar akan memenuhi
         * halaman depan dengan satu baris per unit per hari.
         */
        $this->perangkat();
        app(AbsenUmumService::class)->buka($this->upt->id);

        $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page->has('event_aktif', 0)->etc());
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
