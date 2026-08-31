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
