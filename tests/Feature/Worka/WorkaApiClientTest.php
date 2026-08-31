<?php

namespace Tests\Feature\Worka;

use App\Exceptions\WorkaApiException;
use App\Services\WorkaApiClient;
use App\Support\PengaturanRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkaApiClientTest extends TestCase
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

    protected function klien(): WorkaApiClient
    {
        app(PengaturanRepository::class)->bersihkanIngatan();

        return new WorkaApiClient(app(PengaturanRepository::class));
    }

    #[Test]
    public function amplop_sukses_dibuka_menjadi_data_biasa(): void
    {
        Http::fake([
            'worka.test/api/v1/absen/sync-status' => Http::response([
                'success' => true,
                'data' => [
                    'total_pegawai_aktif' => 766,
                    'terakhir_diupdate' => '2026-08-30T10:00:00Z',
                    'checksum' => 'abc123',
                    'server_time' => '2026-08-31T03:00:00Z',
                ],
            ]),
        ]);

        $status = $this->klien()->getSyncStatus();

        $this->assertSame(766, $status['total_pegawai_aktif']);
        $this->assertSame('abc123', $status['checksum']);
    }

    #[Test]
    public function token_dikirim_sebagai_bearer(): void
    {
        Http::fake(['worka.test/*' => Http::response(['success' => true, 'data' => []])]);

        $this->klien()->getSyncStatus();

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer token-uji'));
    }

    #[Test]
    public function token_dari_pengaturan_mengalahkan_env(): void
    {
        app(PengaturanRepository::class)->simpan(WorkaApiClient::KUNCI_TOKEN, 'token-basis-data', terenkripsi: true);

        Http::fake(['worka.test/*' => Http::response(['success' => true, 'data' => []])]);

        $this->klien()->getSyncStatus();

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer token-basis-data'));
    }

    #[Test]
    public function tanpa_token_langsung_gagal_tanpa_memanggil_worka(): void
    {
        config(['services.worka.api_token' => null]);
        Http::fake();

        try {
            $this->klien()->getSyncStatus();
            $this->fail('Seharusnya melempar WorkaApiException.');
        } catch (WorkaApiException $e) {
            $this->assertSame('TOKEN_KOSONG', $e->workaCode);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function galat_worka_membawa_kode_dan_status(): void
    {
        Http::fake([
            'worka.test/*' => Http::response([
                'success' => false,
                'message' => 'Pegawai dengan NIP tersebut tidak ditemukan',
                'code' => 'PEGAWAI_NOT_FOUND',
            ], 404),
        ]);

        try {
            $this->klien()->getUnitKerja();
            $this->fail('Seharusnya melempar WorkaApiException.');
        } catch (WorkaApiException $e) {
            $this->assertSame(404, $e->httpStatus);
            $this->assertSame('PEGAWAI_NOT_FOUND', $e->workaCode);
            $this->assertSame('Pegawai dengan NIP tersebut tidak ditemukan', $e->workaMessage);
        }
    }

    #[Test]
    public function nip_yang_tidak_ditemukan_mengembalikan_null(): void
    {
        Http::fake([
            'worka.test/api/v1/absen/pegawai/*' => Http::response(
                ['success' => false, 'message' => 'tidak ditemukan', 'code' => 'PEGAWAI_NOT_FOUND'],
                404,
            ),
        ]);

        $this->assertNull($this->klien()->getPegawaiByNip('199001012020011001'));
    }

    #[Test]
    public function token_ditolak_menghasilkan_pesan_yang_dapat_dibaca_admin(): void
    {
        Http::fake(['worka.test/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        try {
            $this->klien()->getSyncStatus();
            $this->fail('Seharusnya melempar WorkaApiException.');
        } catch (WorkaApiException $e) {
            $this->assertStringContainsString('Token API WORKA ditolak', $e->pesanUntukAdmin());
        }
    }

    #[Test]
    public function ability_kurang_menghasilkan_pesan_yang_menyebut_ability(): void
    {
        Http::fake(['worka.test/*' => Http::response(['message' => 'Forbidden.'], 403)]);

        try {
            $this->klien()->getSyncStatus();
            $this->fail('Seharusnya melempar WorkaApiException.');
        } catch (WorkaApiException $e) {
            $this->assertStringContainsString('absen:sync-pegawai', $e->pesanUntukAdmin());
        }
    }

    #[Test]
    public function validasi_nip_meneruskan_jawaban_gagal_apa_adanya(): void
    {
        Http::fake([
            'worka.test/api/v1/absen/validasi-nip' => Http::response([
                'success' => false,
                'message' => 'Pegawai tidak aktif',
                'code' => 'PEGAWAI_TIDAK_AKTIF',
            ], 403),
        ]);

        $hasil = $this->klien()->validasiNip('199001012020011001');

        $this->assertFalse($hasil['success']);
        $this->assertSame('PEGAWAI_TIDAK_AKTIF', $hasil['code']);
    }

    #[Test]
    public function url_foto_disusun_lengkap(): void
    {
        $this->assertSame(
            'http://worka.test/api/v1/absen/pegawai/199001012020011001/foto',
            $this->klien()->getFotoUrl('199001012020011001'),
        );
    }

    #[Test]
    public function cek_koneksi_tidak_melempar_saat_worka_bermasalah(): void
    {
        Http::fake(['worka.test/*' => Http::response([], 500)]);

        $this->assertFalse($this->klien()->cekKoneksi());
    }
}
