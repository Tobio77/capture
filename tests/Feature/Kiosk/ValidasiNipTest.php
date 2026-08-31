<?php

namespace Tests\Feature\Kiosk;

use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Services\KioskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Validasi NIP saat tap dan proxy foto pegawai (FR-TAP-03, NFR-04).
 */
class ValidasiNipTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-perangkat-uji';

    protected UnitKerja $unitKerja;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.worka.api_url' => 'http://worka.test',
            'services.worka.api_token' => 'token-uji',
        ]);

        $this->unitKerja = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);

        Kiosk::factory()->diaktifkan(self::TOKEN)->create([
            'nama_titik' => 'Aula Senam BLK Surabaya',
            'unit_kerja_id' => $this->unitKerja->id,
        ]);
    }

    protected function denganToken(): static
    {
        return $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN);
    }

    #[Test]
    public function nip_terdaftar_dijawab_dari_basis_data_lokal_tanpa_memanggil_worka(): void
    {
        Http::fake();

        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'nama' => 'Ahmad Fauzi',
            'jabatan' => 'Instruktur Pelatihan',
            'unit_kerja_id' => $this->unitKerja->id,
            'foto_tersedia_worka' => true,
        ]);

        $this->denganToken()
            ->post('/kiosk/tap/validasi-nip', ['nip' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'nip' => '199001012020011001',
                    'nama' => 'Ahmad Fauzi',
                    'unit_kerja_kode' => 'BLK-SBY',
                    'unit_kerja_sama' => true,
                    'foto_tersedia' => true,
                ],
            ]);

        Http::assertNothingSent();
    }

    #[Test]
    public function nip_tak_dikenal_dijawab_dengan_kode_yang_dapat_dibaca_mesin(): void
    {
        $this->denganToken()
            ->post('/kiosk/tap/validasi-nip', ['nip' => '199901012020011009'], ['Accept' => 'application/json'])
            ->assertNotFound()
            ->assertJson(['success' => false, 'code' => 'NIP_NOT_FOUND']);
    }

    #[Test]
    public function pegawai_nonaktif_ditolak_bukan_dijawab_sukses(): void
    {
        Pegawai::factory()->create([
            'nip' => '198512312010011001',
            'unit_kerja_id' => $this->unitKerja->id,
            'aktif' => false,
        ]);

        $this->denganToken()
            ->post('/kiosk/tap/validasi-nip', ['nip' => '198512312010011001'], ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJson(['success' => false, 'code' => 'PEGAWAI_TIDAK_AKTIF']);
    }

    #[Test]
    public function pegawai_dari_unit_lain_ditandai_berbeda_unit(): void
    {
        $unitLain = UnitKerja::factory()->create(['kode' => 'BLK-MJK']);

        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $unitLain->id,
        ]);

        $this->denganToken()
            ->post('/kiosk/tap/validasi-nip', ['nip' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['data' => ['unit_kerja_sama' => false]]);
    }

    #[Test]
    public function perangkat_tanpa_token_tidak_dapat_memvalidasi_nip(): void
    {
        Pegawai::factory()->create(['nip' => '199001012020011001', 'unit_kerja_id' => $this->unitKerja->id]);

        $this->post('/kiosk/tap/validasi-nip', ['nip' => '199001012020011001'], ['Accept' => 'application/json'])
            ->assertRedirect('/kiosk/aktivasi');
    }

    #[Test]
    public function foto_di_proxy_dari_worka_tanpa_membocorkan_token(): void
    {
        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->unitKerja->id,
            'foto_tersedia_worka' => true,
        ]);

        Http::fake([
            'worka.test/api/v1/absen/pegawai/199001012020011001/foto' => Http::response(
                'isi-berkas-jpeg',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        $respons = $this->denganToken()->get('/kiosk/pegawai/199001012020011001/foto');

        $respons->assertOk();
        $respons->assertHeader('Content-Type', 'image/jpeg');
        $respons->assertHeader('Cache-Control', 'max-age=3600, private');
        $this->assertSame('isi-berkas-jpeg', $respons->getContent());

        // Token WORKA hanya dipakai server, tidak pernah muncul di jawaban.
        $this->assertStringNotContainsString('token-uji', $respons->getContent());
    }

    #[Test]
    public function proxy_foto_menolak_nip_yang_tidak_dikenal_si_absen(): void
    {
        Http::fake();

        $this->denganToken()
            ->get('/kiosk/pegawai/199901012020011009/foto')
            ->assertNotFound();

        // Rute ini tidak boleh menjadi jalan memindai NIP di WORKA.
        Http::assertNothingSent();
    }

    #[Test]
    public function proxy_foto_tertutup_bagi_perangkat_tanpa_token(): void
    {
        Pegawai::factory()->create(['nip' => '199001012020011001', 'unit_kerja_id' => $this->unitKerja->id]);
        Http::fake();

        $this->get('/kiosk/pegawai/199001012020011001/foto')
            ->assertRedirect('/kiosk/aktivasi');

        Http::assertNothingSent();
    }
}
