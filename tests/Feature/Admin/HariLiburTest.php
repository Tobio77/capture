<?php

namespace Tests\Feature\Admin;

use App\Models\HariLibur;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\KalenderKerjaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
    public function pilihan_unit_hanya_level_teratas(): void
    {
        /*
         * Daftar inilah satu-satunya di aplikasi yang dulu mendatarkan SELURUH
         * tabel unit kerja — seksi dan subbag ikut ditawarkan, padahal absensi
         * tidak pernah diselenggarakan pada tingkat itu. Setiap penyaring lain
         * (Daftar Event, Laporan, Kelola Pegawai, Kelola User, Perangkat
         * Absen, Absen Umum) sudah memakai levelTeratas() sejak S05b.
         */
        $seksi = UnitKerja::factory()->create([
            'kode' => 'BLK-SBY-TU',
            'induk_id' => $this->upt->id,
        ]);

        $this->actingAs($this->superadmin)
            ->get('/admin/kelola-absen/setting')
            ->assertOk()
            ->assertInertia(function (Assert $halaman) use ($seksi) {
                $nilai = collect($halaman->toArray()['props']['unit_kerja_libur'])
                    ->pluck('nilai');

                $this->assertContains($this->upt->id, $nilai->all(), 'UPT harus tetap ditawarkan.');
                $this->assertContains($this->lain->id, $nilai->all());
                $this->assertNotContains($seksi->id, $nilai->all(), 'Seksi tidak boleh ditawarkan.');

                // Simpul OPD adalah INDUK dari level teratas, bukan anggotanya.
                $this->assertNotContains($this->opd->id, $nilai->all());
            });
    }

    #[Test]
    public function libur_unit_induk_tetap_menurun_ke_seksi_di_bawahnya(): void
    {
        /*
         * Penjaga dari salah paham yang paling mahal pada perubahan ini:
         * mempersempit PILIHAN tidak boleh mempersempit JANGKAUAN. Libur yang
         * dipasang pada UPT harus tetap menandai hari bagi seksi di bawahnya,
         * sebab pencarian libur menelusuri ke atas lewat idsLeluhurDan().
         *
         * Bila suatu saat pencariannya diubah menjadi pencocokan
         * unit_kerja_id persis, uji inilah yang akan gagal — bukan
         * laporannya, yang akan tetap terlihat rapi sambil kehilangan orang.
         */
        $seksi = UnitKerja::factory()->create([
            'kode' => 'BLK-SBY-TU',
            'induk_id' => $this->upt->id,
        ]);

        HariLibur::query()->create([
            'tanggal' => '2026-10-01',
            'keterangan' => 'Ulang Tahun BLK Surabaya',
            'unit_kerja_id' => $this->upt->id,
        ]);

        $kalender = app(KalenderKerjaService::class);
        $tanggal = Carbon::parse('2026-10-01');

        $this->assertSame(
            'Ulang Tahun BLK Surabaya',
            $kalender->alasanLibur($seksi->id, $tanggal),
            'Seksi harus mewarisi libur UPT induknya.',
        );

        // Dan tidak bocor ke UPT lain yang sederajat.
        $this->assertNull($kalender->alasanLibur($this->lain->id, $tanggal));
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

    /* ---------------------------------------------------------------------
     * Impor massal (FR-SET-08). Admin mengisi belasan tanggal sekaligus tiap
     * tahun — satu-satu terlalu lambat untuk itu.
     * ------------------------------------------------------------------- */

    #[Test]
    public function impor_menambahkan_banyak_baris_sekaligus(): void
    {
        $teks = <<<'TEKS'
        2026-01-01;Tahun Baru Masehi
        2026-03-19;Hari Raya Nyepi
        2026-12-25;Hari Raya Natal
        TEKS;

        $jawaban = $this->actingAs($this->superadmin)
            ->postJson(self::URL.'/impor', ['teks' => $teks])
            ->assertOk();

        $jawaban->assertJson(['dilewati' => []]);
        $this->assertCount(3, $jawaban->json('ditambahkan'));
        $this->assertDatabaseCount('hari_libur', 3);
        $this->assertSame(
            'Hari Raya Nyepi',
            HariLibur::query()->whereDate('tanggal', '2026-03-19')->value('keterangan'),
        );
    }

    #[Test]
    public function impor_melewati_baris_duplikat_tanpa_menggagalkan_baris_lain(): void
    {
        // Baris yang salah tidak boleh menggagalkan baris lain di sekitarnya
        // — "kalender yang gagal diam-diam lebih buruk daripada kalender yang
        // diisi tangan" (catatan migrasinya sendiri).
        HariLibur::query()->create(['tanggal' => '2026-03-19', 'keterangan' => 'Sudah ada duluan']);

        $teks = <<<'TEKS'
        2026-01-01;Tahun Baru Masehi
        2026-03-19;Hari Raya Nyepi
        2026-12-25;Hari Raya Natal
        TEKS;

        $jawaban = $this->actingAs($this->superadmin)
            ->postJson(self::URL.'/impor', ['teks' => $teks])
            ->assertOk();

        $this->assertCount(2, $jawaban->json('ditambahkan'));
        $this->assertCount(1, $jawaban->json('dilewati'));
        $this->assertSame(2, $jawaban->json('dilewati.0.baris'));
        $this->assertStringContainsString('sudah terdaftar', $jawaban->json('dilewati.0.alasan'));

        // Baris yang sudah ada sebelum impor tidak ikut tertimpa.
        $this->assertSame(
            'Sudah ada duluan',
            HariLibur::query()->whereDate('tanggal', '2026-03-19')->value('keterangan'),
        );
        $this->assertDatabaseCount('hari_libur', 3);
    }

    #[Test]
    public function impor_menandai_duplikat_dalam_satu_tempelan_yang_sama(): void
    {
        // Dua baris tanggal sama dalam SATU tempelan — bukan bentrok dengan
        // data lama, melainkan admin yang tidak sadar menempel dua kali.
        $teks = <<<'TEKS'
        2026-03-19;Hari Raya Nyepi
        2026-03-19;Nyepi (salinan)
        TEKS;

        $jawaban = $this->actingAs($this->superadmin)
            ->postJson(self::URL.'/impor', ['teks' => $teks])
            ->assertOk();

        $this->assertCount(1, $jawaban->json('ditambahkan'));
        $this->assertCount(1, $jawaban->json('dilewati'));
        $this->assertSame(2, $jawaban->json('dilewati.0.baris'));
        $this->assertDatabaseCount('hari_libur', 1);
    }

    #[Test]
    public function impor_melewati_tanggal_yang_tidak_dikenali(): void
    {
        $teks = <<<'TEKS'
        2026-01-01;Tahun Baru Masehi
        25 Desember 2026;Hari Raya Natal
        2026-02-30;Tanggal yang tidak pernah ada
        TEKS;

        $jawaban = $this->actingAs($this->superadmin)
            ->postJson(self::URL.'/impor', ['teks' => $teks])
            ->assertOk();

        $this->assertCount(1, $jawaban->json('ditambahkan'));
        $this->assertCount(2, $jawaban->json('dilewati'));
        $this->assertSame(2, $jawaban->json('dilewati.0.baris'));
        $this->assertSame(3, $jawaban->json('dilewati.1.baris'));

        // "2026-02-30" tidak boleh diam-diam menjadi 2 Maret.
        $this->assertDatabaseMissing('hari_libur', ['keterangan' => 'Tanggal yang tidak pernah ada']);
    }

    #[Test]
    public function impor_melewati_baris_tanpa_keterangan(): void
    {
        $jawaban = $this->actingAs($this->superadmin)
            ->postJson(self::URL.'/impor', ['teks' => '2026-01-01;'])
            ->assertOk();

        $this->assertCount(0, $jawaban->json('ditambahkan'));
        $this->assertCount(1, $jawaban->json('dilewati'));
        $this->assertStringContainsString('tidak boleh kosong', $jawaban->json('dilewati.0.alasan'));
    }

    #[Test]
    public function admin_upt_tidak_dapat_mengimpor_sama_sekali(): void
    {
        /*
         * Sama seperti store()/destroy(): rute impor ini pun berada dalam
         * grup middleware `peran:superadmin,admin_dinas`, sehingga Admin UPT
         * tidak pernah sampai ke pemeriksaan cakupan di controller — apa pun
         * unit yang diminta, bahkan unitnya sendiri.
         */
        $this->actingAs($this->adminUpt)
            ->postJson(self::URL.'/impor', [
                'teks' => '2026-10-01;HUT UPT',
                'unit_kerja_id' => $this->upt->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('hari_libur', 0);
    }

    #[Test]
    public function impor_yang_berhasil_tercatat_pada_audit_trail(): void
    {
        // Per baris, sama seperti tambah satu-satu — bukan satu rangkuman
        // samar yang menyembunyikan tanggal mana saja yang ditambahkan.
        $this->actingAs($this->superadmin)
            ->postJson(self::URL.'/impor', ['teks' => '2026-01-01;Tahun Baru Masehi'])
            ->assertOk();

        $this->assertDatabaseHas('log_aktivitas', [
            'deskripsi' => 'Menambah hari libur 2026-01-01 — Tahun Baru Masehi (seluruh unit).',
        ]);
    }
}
