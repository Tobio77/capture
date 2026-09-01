<?php

namespace Tests\Feature\Worka;

use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Services\SinkronisasiPegawaiService;
use App\Support\PengaturanRepository;
use App\Support\SinkronResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sinkronisasi pegawai dari WORKA (FR-PEG-01, FR-PEG-02).
 */
class SinkronisasiPegawaiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.worka.api_url' => 'http://worka.test',
            'services.worka.api_token' => 'token-uji',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pegawai
     * @param  array<int, array<string, mixed>>  $unitKerja
     */
    protected function palsukanWorka(
        array $pegawai = [],
        array $unitKerja = [],
        string $checksum = 'checksum-1',
        int $lastPage = 1,
    ): void {
        Http::fake([
            'worka.test/api/v1/absen/sync-status' => Http::response([
                'success' => true,
                'data' => [
                    'total_pegawai_aktif' => count($pegawai),
                    'terakhir_diupdate' => '2026-08-30T10:00:00Z',
                    'checksum' => $checksum,
                    'server_time' => '2026-08-31T03:00:00Z',
                ],
            ]),
            'worka.test/api/v1/absen/unit-kerja*' => Http::response([
                'success' => true,
                'data' => $unitKerja,
                'meta' => ['total' => count($unitKerja)],
            ]),
            'worka.test/api/v1/absen/pegawai*' => Http::response([
                'success' => true,
                'data' => $pegawai,
                'meta' => [
                    'total' => count($pegawai),
                    'per_page' => 500,
                    'current_page' => 1,
                    'last_page' => $lastPage,
                    'sync_timestamp' => '2026-08-31T03:00:00Z',
                ],
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function barisPegawai(string $nip, string $nama, string $kodeUnit = 'BLK-SBY', bool $aktif = true): array
    {
        return [
            'id' => crc32($nip),
            'nip' => $nip,
            'nama' => $nama,
            'unit_kerja' => ['id' => 7, 'kode' => $kodeUnit, 'nama' => 'UPT BLK Surabaya', 'nama_induk' => null],
            'jabatan' => 'Instruktur Pelatihan',
            'kelompok_jabatan' => 'fungsional',
            'golongan' => 'III/a',
            'status_kepegawaian' => 'PNS',
            'status_aktif' => $aktif,
            'jenis_kelamin' => 'L',
            'foto_tersedia' => true,
            'updated_at' => '2026-08-30T10:00:00Z',
        ];
    }

    protected function layanan(): SinkronisasiPegawaiService
    {
        app(PengaturanRepository::class)->bersihkanIngatan();

        return app(SinkronisasiPegawaiService::class);
    }

    #[Test]
    public function unit_kerja_disinkronkan_sebelum_pegawai(): void
    {
        $this->palsukanWorka(
            pegawai: [$this->barisPegawai('199001012020011001', 'Ahmad Fauzi')],
            unitKerja: [['id' => 7, 'kode' => 'BLK-SBY', 'nama' => 'UPT Balai Latihan Kerja Surabaya', 'aktif' => true]],
        );

        $hasil = $this->layanan()->sinkronPenuh();

        $this->assertSame(SinkronResult::SUKSES, $hasil->status);
        $this->assertDatabaseHas('unit_kerja', ['kode' => 'BLK-SBY', 'nama' => 'UPT Balai Latihan Kerja Surabaya']);
        $this->assertDatabaseHas('pegawai', ['nip' => '199001012020011001', 'nama' => 'Ahmad Fauzi']);
        $this->assertSame(1, $hasil->ditambahkan);
    }

    #[Test]
    public function medan_worka_dipetakan_ke_kolom_pegawai(): void
    {
        $this->palsukanWorka(
            pegawai: [$this->barisPegawai('199001012020011001', 'Ahmad Fauzi')],
            unitKerja: [['id' => 7, 'kode' => 'BLK-SBY', 'nama' => 'UPT BLK Surabaya', 'aktif' => true]],
        );

        $this->layanan()->sinkronPenuh();

        $pegawai = Pegawai::sole();

        $this->assertSame('Ahmad Fauzi', $pegawai->nama);
        $this->assertSame('Instruktur Pelatihan', $pegawai->jabatan);
        $this->assertSame('BLK-SBY', $pegawai->unitKerja->kode);
        $this->assertTrue($pegawai->aktif);
        $this->assertTrue($pegawai->foto_tersedia_worka);
        $this->assertNotNull($pegawai->sumber_sinkron_terakhir);
    }

    #[Test]
    public function checksum_yang_sama_melewatkan_sinkronisasi(): void
    {
        app(PengaturanRepository::class)->simpan(SinkronisasiPegawaiService::KUNCI_CHECKSUM, 'checksum-1');

        $this->palsukanWorka(checksum: 'checksum-1');

        $hasil = $this->layanan()->sinkronInkremental();

        $this->assertSame(SinkronResult::TIDAK_ADA_PERUBAHAN, $hasil->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/absen/pegawai?'));
    }

    #[Test]
    public function sinkronisasi_penuh_tetap_berjalan_walau_checksum_sama(): void
    {
        app(PengaturanRepository::class)->simpan(SinkronisasiPegawaiService::KUNCI_CHECKSUM, 'checksum-1');

        $this->palsukanWorka(
            pegawai: [$this->barisPegawai('199001012020011001', 'Ahmad Fauzi')],
            unitKerja: [['id' => 7, 'kode' => 'BLK-SBY', 'nama' => 'UPT BLK Surabaya', 'aktif' => true]],
            checksum: 'checksum-1',
        );

        $this->assertSame(SinkronResult::SUKSES, $this->layanan()->sinkronPenuh()->status);
        $this->assertDatabaseCount('pegawai', 1);
    }

    #[Test]
    public function inkremental_mengirim_updated_after_dari_sinkronisasi_terakhir(): void
    {
        app(PengaturanRepository::class)->simpanBanyak([
            SinkronisasiPegawaiService::KUNCI_CHECKSUM => 'checksum-lama',
            SinkronisasiPegawaiService::KUNCI_SINKRON_TERAKHIR => '2026-08-30T02:00:00+00:00',
        ]);

        $this->palsukanWorka(
            pegawai: [$this->barisPegawai('199001012020011001', 'Ahmad Fauzi')],
            unitKerja: [['id' => 7, 'kode' => 'BLK-SBY', 'nama' => 'UPT BLK Surabaya', 'aktif' => true]],
            checksum: 'checksum-baru',
        );

        $this->layanan()->sinkronInkremental();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'updated_after=2026-08-30T02%3A00%3A00%2B00%3A00')
            || str_contains(urldecode($r->url()), 'updated_after=2026-08-30T02:00:00+00:00'));
    }

    #[Test]
    public function pegawai_yang_hilang_dari_worka_dinonaktifkan_bukan_dihapus(): void
    {
        $unitKerja = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);
        $lama = Pegawai::factory()->create([
            'nip' => '198512312010011001',
            'unit_kerja_id' => $unitKerja->id,
            'aktif' => true,
        ]);

        $this->palsukanWorka(
            pegawai: [$this->barisPegawai('199001012020011001', 'Ahmad Fauzi')],
            unitKerja: [['id' => 7, 'kode' => 'BLK-SBY', 'nama' => 'UPT BLK Surabaya', 'aktif' => true]],
        );

        $hasil = $this->layanan()->sinkronPenuh();

        $this->assertSame(1, $hasil->dinonaktifkan);
        $this->assertFalse($lama->refresh()->aktif);
        $this->assertDatabaseCount('pegawai', 2);
    }

    #[Test]
    public function sinkronisasi_inkremental_tidak_menonaktifkan_siapa_pun(): void
    {
        $unitKerja = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);
        $lama = Pegawai::factory()->create(['nip' => '198512312010011001', 'unit_kerja_id' => $unitKerja->id]);

        $this->palsukanWorka(
            pegawai: [$this->barisPegawai('199001012020011001', 'Ahmad Fauzi')],
            unitKerja: [['id' => 7, 'kode' => 'BLK-SBY', 'nama' => 'UPT BLK Surabaya', 'aktif' => true]],
        );

        $hasil = $this->layanan()->sinkronInkremental();

        $this->assertSame(0, $hasil->dinonaktifkan);
        $this->assertTrue($lama->refresh()->aktif);
    }

    #[Test]
    public function jawaban_kosong_tidak_menonaktifkan_seluruh_pegawai(): void
    {
        $unitKerja = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);
        Pegawai::factory()->count(3)->create(['unit_kerja_id' => $unitKerja->id, 'aktif' => true]);

        $this->palsukanWorka(pegawai: [], unitKerja: []);

        $hasil = $this->layanan()->sinkronPenuh();

        $this->assertSame(0, $hasil->dinonaktifkan);
        $this->assertSame(3, Pegawai::where('aktif', true)->count());
    }

    #[Test]
    public function keikutsertaan_unit_kerja_yang_dinonaktifkan_admin_tidak_dipaksa_aktif_kembali(): void
    {
        UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'nama' => 'Nama Lama', 'aktif' => false]);

        $this->palsukanWorka(
            unitKerja: [['id' => 7, 'kode' => 'BLK-SBY', 'nama' => 'UPT BLK Surabaya', 'aktif' => true]],
        );

        $this->layanan()->sinkronPenuh();

        $unit = UnitKerja::sole();

        // Nama tetap mengikuti WORKA, keikutsertaan tetap keputusan admin.
        $this->assertSame('UPT BLK Surabaya', $unit->nama);
        $this->assertFalse($unit->aktif);
    }

    #[Test]
    public function unit_yang_dinonaktifkan_di_worka_ikut_dinonaktifkan(): void
    {
        UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'aktif' => true]);

        $this->palsukanWorka(
            unitKerja: [['id' => 7, 'kode' => 'BLK-SBY', 'nama' => 'UPT BLK Surabaya', 'aktif' => false]],
        );

        $this->layanan()->sinkronPenuh();

        $this->assertFalse(UnitKerja::sole()->aktif);
    }

    #[Test]
    public function induk_unit_kerja_tertaut_walau_anak_dikirim_lebih_dulu(): void
    {
        // Urutan sengaja dibalik: anak lebih dulu, induknya belakangan —
        // inilah alasan sinkronisasi unit berjalan dua tahap.
        $this->palsukanWorka(unitKerja: [
            [
                'id' => 52, 'kode' => 'BLK-SGS-TU', 'nama' => 'Sub Bagian Tata Usaha', 'aktif' => true,
                'parent' => ['id' => 51, 'kode' => 'BLK-SGS', 'nama' => 'UPT BLK Singosari'],
            ],
            [
                'id' => 51, 'kode' => 'BLK-SGS', 'nama' => 'UPT BLK Singosari', 'aktif' => true,
                'parent' => ['id' => 2, 'kode' => 'DISNAKERTRANS', 'nama' => 'Dinas Tenaga Kerja dan Transmigrasi'],
            ],
            [
                'id' => 2, 'kode' => 'DISNAKERTRANS', 'nama' => 'Dinas Tenaga Kerja dan Transmigrasi', 'aktif' => true,
                'parent' => null,
            ],
        ]);

        $this->layanan()->sinkronPenuh();

        $dinas = UnitKerja::query()->where('kode', 'DISNAKERTRANS')->sole();
        $upt = UnitKerja::query()->where('kode', 'BLK-SGS')->sole();
        $subbag = UnitKerja::query()->where('kode', 'BLK-SGS-TU')->sole();

        $this->assertNull($dinas->induk_id, 'Unit puncak tidak berinduk.');
        $this->assertSame($dinas->id, $upt->induk_id);
        $this->assertSame($upt->id, $subbag->induk_id);
        $this->assertSame('BLK-SGS', $subbag->induk->kode);
    }

    #[Test]
    public function induk_yang_tidak_dikirim_worka_tidak_memutus_tautan_lama(): void
    {
        $induk = UnitKerja::factory()->create(['kode' => 'BLK-SGS']);
        $anak = UnitKerja::factory()->create(['kode' => 'BLK-SGS-TU', 'induk_id' => $induk->id]);

        // WORKA hanya mengirim anaknya, induknya di luar daftar.
        $this->palsukanWorka(unitKerja: [
            [
                'id' => 52, 'kode' => 'BLK-SGS-TU', 'nama' => 'Sub Bagian Tata Usaha', 'aktif' => true,
                'parent' => ['id' => 51, 'kode' => 'BLK-SGS', 'nama' => 'UPT BLK Singosari'],
            ],
        ]);

        $this->layanan()->sinkronPenuh();

        $this->assertSame($induk->id, $anak->refresh()->induk_id);
    }

    #[Test]
    public function induk_unit_lokal_ditegakkan_setiap_sinkronisasi(): void
    {
        config(['services.worka.induk_unit_lokal' => ['DISNAKER' => 'DISNAKERTRANS']]);

        // DISNAKER tidak pernah dikirim WORKA; induknya hanya dapat ditautkan
        // dari peta, bukan dari jawaban API.
        $lokal = UnitKerja::factory()->create(['kode' => 'DISNAKER', 'induk_id' => null]);

        $this->palsukanWorka(unitKerja: [
            ['id' => 2, 'kode' => 'DISNAKERTRANS', 'nama' => 'Dinas Tenaga Kerja dan Transmigrasi', 'aktif' => true, 'parent' => null],
        ]);

        $this->layanan()->sinkronPenuh();

        $induk = UnitKerja::query()->where('kode', 'DISNAKERTRANS')->sole();
        $this->assertSame($induk->id, $lokal->refresh()->induk_id);
    }

    #[Test]
    public function penautan_unit_lokal_idempoten_saat_sinkronisasi_diulang(): void
    {
        config(['services.worka.induk_unit_lokal' => ['DISNAKER' => 'DISNAKERTRANS']]);

        $lokal = UnitKerja::factory()->create(['kode' => 'DISNAKER', 'induk_id' => null]);

        $this->palsukanWorka(unitKerja: [
            ['id' => 2, 'kode' => 'DISNAKERTRANS', 'nama' => 'Dinas Tenaga Kerja dan Transmigrasi', 'aktif' => true, 'parent' => null],
        ]);

        $pertama = $this->layanan()->sinkronPenuh();
        $indukId = $lokal->refresh()->induk_id;

        $kedua = $this->layanan()->sinkronPenuh();

        // Putaran kedua tidak mengubah tautan dan tidak menghitungnya lagi
        // sebagai perubahan.
        $this->assertSame($indukId, $lokal->refresh()->induk_id);
        $this->assertSame(2, $pertama->unitKerjaDiperbarui);
        $this->assertSame(0, $kedua->unitKerjaDiperbarui);
    }

    #[Test]
    public function unit_lokal_menunggu_induknya_muncul_tanpa_menggagalkan_sinkronisasi(): void
    {
        config(['services.worka.induk_unit_lokal' => ['DISNAKER' => 'DISNAKERTRANS']]);

        $lokal = UnitKerja::factory()->create(['kode' => 'DISNAKER', 'induk_id' => null]);

        // Sinkronisasi pertama: unit induk belum ada sama sekali.
        $this->palsukanWorka(
            unitKerja: [['id' => 7, 'kode' => 'BLK-SBY', 'nama' => 'UPT BLK Surabaya', 'aktif' => true, 'parent' => null]],
        );

        $this->layanan()->sinkronPenuh();
        $this->assertNull($lokal->refresh()->induk_id, 'Tanpa induk, tautan ditunda, bukan digagalkan.');

        // Induk kemudian tersedia; sinkronisasi berikutnya memulihkan hirarki
        // sendiri tanpa perlu seeding ulang atau langkah manual.
        $induk = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);

        $this->layanan()->sinkronPenuh();

        $this->assertSame($induk->id, $lokal->refresh()->induk_id);
    }

    #[Test]
    public function hirarki_worka_menang_atas_peta_unit_lokal(): void
    {
        config(['services.worka.induk_unit_lokal' => ['BLK-SBY' => 'DISNAKERTRANS']]);

        // BLK-SBY ternyata dikirim WORKA dengan induk yang berbeda dari peta;
        // yang berlaku adalah hirarki WORKA.
        $this->palsukanWorka(unitKerja: [
            ['id' => 2, 'kode' => 'DISNAKERTRANS', 'nama' => 'Dinas Tenaga Kerja dan Transmigrasi', 'aktif' => true, 'parent' => null],
            ['id' => 40, 'kode' => 'BID-LAT', 'nama' => 'Bidang Pelatihan', 'aktif' => true, 'parent' => ['id' => 2, 'kode' => 'DISNAKERTRANS', 'nama' => 'Dinas']],
            ['id' => 7, 'kode' => 'BLK-SBY', 'nama' => 'UPT BLK Surabaya', 'aktif' => true, 'parent' => ['id' => 40, 'kode' => 'BID-LAT', 'nama' => 'Bidang Pelatihan']],
        ]);

        $this->layanan()->sinkronPenuh();

        $bidang = UnitKerja::query()->where('kode', 'BID-LAT')->sole();
        $this->assertSame($bidang->id, UnitKerja::query()->where('kode', 'BLK-SBY')->sole()->induk_id);
    }

    #[Test]
    public function unit_lokal_di_luar_daftar_worka_tidak_disentuh(): void
    {
        $lokal = UnitKerja::factory()->create(['kode' => 'UPT-LOKAL', 'nama' => 'Unit Buatan Admin']);

        $this->palsukanWorka(
            unitKerja: [['id' => 7, 'kode' => 'BLK-SBY', 'nama' => 'UPT BLK Surabaya', 'aktif' => true, 'parent' => null]],
        );

        $this->layanan()->sinkronPenuh();

        $this->assertSame('Unit Buatan Admin', $lokal->refresh()->nama);
        $this->assertNull($lokal->induk_id);
        $this->assertTrue($lokal->aktif);
    }

    #[Test]
    public function pegawai_dengan_unit_kerja_tak_dikenal_dilewati_tanpa_menggagalkan_sinkronisasi(): void
    {
        $this->palsukanWorka(
            pegawai: [
                $this->barisPegawai('199001012020011001', 'Ahmad Fauzi', 'BLK-SBY'),
                $this->barisPegawai('199101012020011002', 'Unit Asing', 'UNIT-TIDAK-ADA'),
            ],
            unitKerja: [['id' => 7, 'kode' => 'BLK-SBY', 'nama' => 'UPT BLK Surabaya', 'aktif' => true]],
        );

        $hasil = $this->layanan()->sinkronPenuh();

        $this->assertSame(SinkronResult::SUKSES, $hasil->status);
        $this->assertSame(1, $hasil->ditambahkan);
        $this->assertDatabaseMissing('pegawai', ['nip' => '199101012020011002']);
    }

    #[Test]
    public function foto_referensi_milik_si_absen_tidak_ditimpa_sinkronisasi(): void
    {
        $unitKerja = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);
        $pegawai = Pegawai::factory()->wajahTerdaftar()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $unitKerja->id,
        ]);
        $jalurFoto = $pegawai->foto_referensi_path;

        $this->palsukanWorka(
            pegawai: [$this->barisPegawai('199001012020011001', 'Ahmad Fauzi')],
            unitKerja: [['id' => 7, 'kode' => 'BLK-SBY', 'nama' => 'UPT BLK Surabaya', 'aktif' => true]],
        );

        $this->layanan()->sinkronPenuh();

        $pegawai->refresh();

        $this->assertSame('Ahmad Fauzi', $pegawai->nama);
        $this->assertTrue($pegawai->wajah_terdaftar);
        $this->assertSame($jalurFoto, $pegawai->foto_referensi_path);
    }

    #[Test]
    public function tanpa_token_sinkronisasi_gagal_dengan_pesan_yang_jelas(): void
    {
        config(['services.worka.api_token' => null]);
        Http::fake();

        $hasil = $this->layanan()->sinkronInkremental();

        $this->assertSame(SinkronResult::GAGAL, $hasil->status);
        $this->assertStringContainsString('belum dikonfigurasi', $hasil->error);
        Http::assertNothingSent();
    }

    #[Test]
    public function worka_bermasalah_menghasilkan_hasil_gagal_bukan_exception(): void
    {
        Http::fake(['worka.test/*' => Http::response([], 503)]);

        $hasil = $this->layanan()->sinkronInkremental();

        $this->assertSame(SinkronResult::GAGAL, $hasil->status);
        $this->assertStringContainsString('WORKA sedang bermasalah', $hasil->error);
    }

    #[Test]
    public function perintah_artisan_menjalankan_sinkronisasi(): void
    {
        $this->palsukanWorka(
            pegawai: [$this->barisPegawai('199001012020011001', 'Ahmad Fauzi')],
            unitKerja: [['id' => 7, 'kode' => 'BLK-SBY', 'nama' => 'UPT BLK Surabaya', 'aktif' => true]],
        );

        $this->artisan('pegawai:sinkron --penuh')
            ->expectsOutputToContain('Pegawai ditambahkan: 1')
            ->assertSuccessful();

        $this->assertDatabaseCount('pegawai', 1);
    }

    #[Test]
    public function perintah_artisan_gagal_bila_worka_tidak_dapat_dihubungi(): void
    {
        config(['services.worka.api_token' => null]);
        Http::fake();

        $this->artisan('pegawai:sinkron')->assertFailed();
    }
}
