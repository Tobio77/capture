<?php

namespace Tests\Feature\Admin;

use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\AbsensiService;
use App\Services\KioskService;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tab Rekap Umum pada menu Rekap Absen (FR-REK-01, FR-REK-02).
 *
 * Yang diuji di sini bukan cara menampilkannya, melainkan bahwa tab ini
 * memang membaca sumber yang sama dengan halaman Absen Umum. Barisnya
 * sengaja tidak ditulis ulang di RekapController — dan satu-satunya cara
 * memastikan itu tetap begitu adalah membandingkan keluaran kedua halaman
 * pada data yang sama, sehingga salinan kedua yang menyimpang akan langsung
 * memerahkan uji ini, bukan diketahui berbulan-bulan kemudian dari laporan
 * yang angkanya berbeda.
 */
class RekapTabUmumTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-perangkat-rekap';

    protected const URL = '/admin/kelola-absen/rekap';

    protected UnitKerja $upt;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AbsensiService::DISK);

        // 07.35 berada di dalam jendela datang bawaan (FR-SET-07); tanpa ini
        // uji ini lulus atau gagal tergantung jam berapa ia dijalankan.
        $this->travelTo('2026-09-07 07:35:00');

        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $this->upt = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $opd->id]);

        Kiosk::factory()->diaktifkan(self::TOKEN)->create(['unit_kerja_id' => $this->upt->id]);

        $this->admin = User::factory()->superadmin()->create();

        app(SettingAbsenService::class)->simpan(['metode_wajah_aktif' => false], $this->admin);
    }

    protected function pegawai(string $nip, string $nama): Pegawai
    {
        return Pegawai::factory()->create([
            'nip' => $nip,
            'nama' => $nama,
            'unit_kerja_id' => $this->upt->id,
        ]);
    }

    protected function tap(string $nip, string $jenis = 'datang'): void
    {
        $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->post('/kiosk/umum/absen', [
                'id_card' => $nip,
                'jenis' => $jenis,
                'metode' => 'manual',
            ], ['Accept' => 'application/json'])
            ->assertOk();
    }

    #[Test]
    public function tanpa_tab_yang_disebut_halaman_membuka_rekap_kegiatan(): void
    {
        $this->actingAs($this->admin)
            ->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->component('Rekap/Index')
                ->where('tab', 'event')
                ->where('umum', null)
                ->etc());
    }

    #[Test]
    public function tab_umum_menampilkan_kehadiran_harian(): void
    {
        $this->pegawai('199001012020011001', 'Ahmad Fauzi');
        $this->tap('199001012020011001');

        $this->actingAs($this->admin)
            ->get(self::URL."?tab=umum&unit_kerja_id={$this->upt->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->component('Rekap/Index')
                ->where('tab', 'umum')
                ->has('umum.baris', 1)
                ->where('umum.baris.0.nama', 'Ahmad Fauzi')
                ->where('umum.baris.0.jam_masuk', '07:35')
                ->where('ringkasan.hadir', 1)
                ->etc());
    }

    #[Test]
    public function baris_tab_umum_sama_persis_dengan_halaman_absen_umum(): void
    {
        /*
         * Inti uji ini. Keduanya harus menjawab dari sumber yang sama; begitu
         * salah satunya ditulis ulang, perbedaan sekecil apa pun — kolom yang
         * hilang, jam yang diformat lain, urutan yang berbeda — muncul di sini.
         */
        $this->pegawai('199001012020011001', 'Ahmad Fauzi');
        $this->pegawai('199203032021012002', 'Siti Rahayu');

        $this->tap('199001012020011001');
        $this->travelTo('2026-09-07 08:40:00');
        $this->tap('199203032021012002');

        $rekap = $this->actingAs($this->admin)
            ->get(self::URL."?tab=umum&unit_kerja_id={$this->upt->id}");

        $absenUmum = $this->actingAs($this->admin)
            ->get("/admin/kelola-absen/absen-umum?unit_kerja_id={$this->upt->id}");

        $this->assertSame(
            $absenUmum->viewData('page')['props']['baris'],
            $rekap->viewData('page')['props']['umum']['baris'],
            'Tab Rekap Umum dan halaman Absen Umum harus membaca sumber baris yang sama.',
        );

        $this->assertSame(
            $absenUmum->viewData('page')['props']['ringkasan'],
            $rekap->viewData('page')['props']['ringkasan'],
        );
    }

    #[Test]
    public function pencarian_tab_umum_menyaring_menurut_nama_dan_nip(): void
    {
        $this->pegawai('199001012020011001', 'Ahmad Fauzi');
        $this->pegawai('199203032021012002', 'Siti Rahayu');

        $this->tap('199001012020011001');
        $this->tap('199203032021012002');

        $this->actingAs($this->admin)
            ->get(self::URL."?tab=umum&unit_kerja_id={$this->upt->id}&cari=siti")
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->has('umum.baris', 1)
                ->where('umum.baris.0.nama', 'Siti Rahayu')
                ->etc());

        $this->actingAs($this->admin)
            ->get(self::URL."?tab=umum&unit_kerja_id={$this->upt->id}&cari=199001")
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->has('umum.baris', 1)
                ->where('umum.baris.0.nama', 'Ahmad Fauzi')
                ->etc());
    }

    #[Test]
    public function tab_umum_membaca_tanggal_yang_diminta(): void
    {
        $this->pegawai('199001012020011001', 'Ahmad Fauzi');
        $this->tap('199001012020011001');

        // Sesi kemarin tidak pernah ada, jadi tabelnya kosong — bukan tertukar
        // dengan sesi hari ini.
        $this->actingAs($this->admin)
            ->get(self::URL."?tab=umum&unit_kerja_id={$this->upt->id}&tanggal=2026-09-06")
            ->assertOk()
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->where('umum.sesi', null)
                ->has('umum.baris', 0)
                ->etc());
    }

    #[Test]
    public function admin_upt_hanya_melihat_pegawai_unitnya_sendiri(): void
    {
        // FR-REK-02, diuji di tab ini karena cakupan yang bocor pada halaman
        // rekap adalah kebocoran data, bukan sekadar tampilan yang keliru.
        $lain = UnitKerja::factory()->create(['kode' => 'BLK-MLG']);

        $this->pegawai('199001012020011001', 'Ahmad Fauzi');
        $this->tap('199001012020011001');

        $adminLain = User::factory()->adminUpt($lain)->create();

        $this->actingAs($adminLain)
            ->get(self::URL.'?tab=umum')
            ->assertOk()
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->has('umum.baris', 0)
                ->etc());
    }
}
