<?php

namespace Tests\Feature\Admin;

use App\Enums\CakupanEvent;
use App\Models\EventAbsen;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\AbsenUmumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cakupan "Wilayah Kerja Surabaya" dan unit bawaan Absen Umum (revisi S29).
 *
 * Keduanya bertumpu pada KODE unit kerja hasil sinkronisasi WORKA, bukan pada
 * id — id berbeda antar lingkungan, dan sebagian unit Surabaya bernomor kecil
 * karena ikut sinkronisasi awal sementara sebagian menyusul belakangan.
 */
class CakupanWilayahDanDefaultUnitTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = '/admin/kelola-absen/event';

    protected UnitKerja $opd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->opd = UnitKerja::factory()->create([
            'kode' => 'DISNAKERTRANS',
            'nama' => 'Dinas Tenaga Kerja dan Transmigrasi',
        ]);
    }

    /**
     * Susun unit kerja Wilayah Kerja Surabaya seperti hasil sinkronisasi
     * WORKA, beserta satu unit di luar wilayah sebagai pembanding.
     */
    protected function unitWilayah(): void
    {
        $nama = [
            'BLK-SBY' => 'UPT Balai Latihan Kerja di Surabaya',
            'UPT-K3' => 'UPT Keselamatan Kerja',
            'UPT-BLPP' => 'UPT Balai Latihan Pengembangan Produktivitas Tenaga Kerja di Surabaya',
            'UPT-P2TK' => 'UPT Pelayanan dan Perlindungan Tenaga Kerja',
        ];

        foreach ($nama as $kode => $lengkap) {
            UnitKerja::factory()->create([
                'kode' => $kode,
                'nama' => $lengkap,
                'induk_id' => $this->opd->id,
            ]);
        }

        UnitKerja::factory()->create([
            'kode' => 'BLK-MJK',
            'nama' => 'UPT Balai Latihan Kerja di Mojokerto',
            'induk_id' => $this->opd->id,
        ]);
    }

    /* ---------------------------------------------------------------------
     * Cakupan Wilayah Kerja Surabaya.
     * ------------------------------------------------------------------- */

    #[Test]
    public function cakupan_wilayah_mengisi_unitnya_sendiri_tanpa_dicentang_admin(): void
    {
        $this->unitWilayah();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, [
                'nama' => 'Apel Wilayah Surabaya',
                'tanggal' => '2026-09-07',
                'jam_mulai' => '07:30',
                'toleransi_menit' => 15,
                'cakupan' => CakupanEvent::WilayahSurabaya->value,

                // Kiriman peramban sengaja keliru: daftarnya ditentukan enum,
                // bukan apa pun yang dikirim formulir.
                'unit_kerja_id' => [],
                'catatan' => null,
            ])
            ->assertSessionHas('sukses');

        $event = EventAbsen::query()->latest('id')->sole();

        $diharapkan = CakupanEvent::KODE_WILAYAH_SURABAYA;
        sort($diharapkan);

        $this->assertSame(
            $diharapkan,
            $event->unitKerja->pluck('kode')->sort()->values()->all(),
            'Cakupan wilayah harus memuat persis empat unit yang tertanam pada enum.',
        );
    }

    #[Test]
    public function cakupan_wilayah_menyimpan_pivot_sehingga_perangkat_tetap_tercocokkan(): void
    {
        /*
         * Berbeda dari "semua unit", yang justru TIDAK menyimpan baris pivot,
         * cakupan bawaan sistem mengisi pivotnya. Itulah yang membuat seluruh
         * mesin yang membaca cakupan lewat pivot — rekap, pencocokan perangkat,
         * kode unit kerja per event — bekerja tanpa perlu mengenali cakupan
         * baru ini sama sekali.
         */
        $this->unitWilayah();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, [
                'nama' => 'Apel Wilayah Surabaya',
                'tanggal' => '2026-09-07',
                'jam_mulai' => '07:30',
                'toleransi_menit' => 15,
                'cakupan' => CakupanEvent::WilayahSurabaya->value,
                'unit_kerja_id' => [],
                'catatan' => null,
            ]);

        $event = EventAbsen::query()->latest('id')->sole();

        $this->assertFalse($event->berlakuUntukSemuaUnit());
        $this->assertSame(4, $event->kodeUnit()->count());
    }

    #[Test]
    public function cakupan_wilayah_ditolak_bila_ada_unit_yang_belum_tersinkron(): void
    {
        /*
         * Menyimpan event dengan cakupan yang bolong lebih berbahaya daripada
         * menolaknya: pegawai unit yang hilang tidak akan dapat mengabsen, dan
         * tidak ada yang menyadarinya sampai hari-H.
         */
        $this->unitWilayah();
        UnitKerja::query()->where('kode', 'UPT-P2TK')->delete();

        $this->actingAs(User::factory()->superadmin()->create())
            ->post(self::URL, [
                'nama' => 'Apel Wilayah Surabaya',
                'tanggal' => '2026-09-07',
                'jam_mulai' => '07:30',
                'toleransi_menit' => 15,
                'cakupan' => CakupanEvent::WilayahSurabaya->value,
                'unit_kerja_id' => [],
                'catatan' => null,
            ])
            ->assertSessionHasErrors('cakupan');

        $this->assertDatabaseCount('event_absen', 0);
    }

    #[Test]
    public function admin_upt_tidak_dapat_memakai_cakupan_wilayah(): void
    {
        // FR-EVT-02: cakupan yang melampaui satu unit hanya untuk peran lintas
        // unit — wilayah kerja mencakup empat UPT sekaligus.
        $this->unitWilayah();

        $upt = UnitKerja::query()->where('kode', 'BLK-SBY')->sole();

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->post(self::URL, [
                'nama' => 'Apel Wilayah Surabaya',
                'tanggal' => '2026-09-07',
                'jam_mulai' => '07:30',
                'toleransi_menit' => 15,
                'cakupan' => CakupanEvent::WilayahSurabaya->value,
                'unit_kerja_id' => [],
                'catatan' => null,
            ])
            ->assertSessionHasErrors('cakupan');
    }

    #[Test]
    public function formulir_event_memperlihatkan_unit_penyusun_cakupan_wilayah(): void
    {
        // Kekeliruan pemetaan kode harus ketahuan di layar sebelum event
        // disimpan, bukan setelah kegiatannya berjalan.
        $this->unitWilayah();

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->has('cakupan_tertanam', 1)
                ->where('cakupan_tertanam.0.nilai', CakupanEvent::WilayahSurabaya->value)
                ->has('cakupan_tertanam.0.unit_kerja', 4)
                ->etc());
    }

    /* ---------------------------------------------------------------------
     * Unit bawaan Absen Umum.
     * ------------------------------------------------------------------- */

    #[Test]
    public function pilihan_absen_umum_dimulai_dari_simpul_opd(): void
    {
        /*
         * Sebelum revisi S29, bawaannya jatuh ke unit pertama menurut abjad —
         * "Bidang Hubungan Industrial…" — semata-mata karena itulah baris
         * pertama daftar. Admin yang tidak menyadarinya memantau sesi yang
         * salah sepanjang hari.
         */
        $this->unitWilayah();

        $tersedia = app(AbsenUmumService::class)
            ->unitTersedia(User::factory()->superadmin()->create());

        $this->assertSame('DISNAKERTRANS', $tersedia->first()['kode']);
    }

    #[Test]
    public function sesi_absen_umum_opd_mencakup_seluruh_pegawai_dinas(): void
    {
        $this->unitWilayah();

        $sesi = app(AbsenUmumService::class)->buka($this->opd->id);

        $this->assertNotNull($sesi);
        $this->assertSame([$this->opd->id], $sesi->unitKerja->pluck('id')->all());

        // Cakupannya meliputi seluruh unit di bawahnya — lima UPT pada
        // penyiapan ini, ditambah simpul OPD sendiri.
        $this->assertCount(6, UnitKerja::idsDenganTurunan($this->opd->id));
    }

    #[Test]
    public function halaman_absen_umum_membuka_sesi_opd_secara_bawaan(): void
    {
        $this->unitWilayah();

        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/admin/kelola-absen/absen-umum')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filter.unit_kerja_id', $this->opd->id)
                ->where('unit_kerja.0.kode', 'DISNAKERTRANS')
                ->etc());
    }

    #[Test]
    public function admin_upt_tetap_terkunci_pada_unitnya_sendiri(): void
    {
        // Simpul OPD hanya ditambahkan bagi peran lintas unit; Admin UPT tidak
        // boleh memantau kehadiran seluruh dinas (FR-REK-02).
        $this->unitWilayah();

        $upt = UnitKerja::query()->where('kode', 'BLK-SBY')->sole();

        $tersedia = app(AbsenUmumService::class)
            ->unitTersedia(User::factory()->adminUpt($upt)->create());

        $this->assertSame(['BLK-SBY'], $tersedia->pluck('kode')->all());
    }

    #[Test]
    public function unit_di_luar_level_teratas_tetap_ditolak(): void
    {
        // Simpul OPD adalah pengecualian yang dinyatakan, bukan pintu terbuka:
        // seksi/subbag tetap tidak dapat dipilih sebagai pemilik sesi harian.
        $this->unitWilayah();

        $upt = UnitKerja::query()->where('kode', 'BLK-SBY')->sole();
        $seksi = UnitKerja::factory()->create(['kode' => 'BLK-SBY-TU', 'induk_id' => $upt->id]);

        $terpilih = app(AbsenUmumService::class)->unitTerpilih(
            User::factory()->superadmin()->create(),
            $seksi->id,
        );

        $this->assertNull($terpilih);
    }
}
