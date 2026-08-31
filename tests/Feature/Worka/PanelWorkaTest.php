<?php

namespace Tests\Feature\Worka;

use App\Models\Pegawai;
use App\Models\Pengaturan;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\WorkaApiClient;
use App\Support\PengaturanRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Halaman Kelola Pegawai dan Setting → Integrasi WORKA.
 */
class PanelWorkaTest extends TestCase
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

    #[Test]
    public function halaman_pegawai_menampilkan_daftar_dan_status_sinkronisasi(): void
    {
        $unitKerja = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);
        Pegawai::factory()->count(3)->create(['unit_kerja_id' => $unitKerja->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/admin/pegawai')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Pegawai/Index')
                ->has('pegawai.data', 3)
                ->where('dapat_sinkron', true)
                ->where('status_sinkron.terkonfigurasi', true)
                ->etc());
    }

    #[Test]
    public function pencarian_pegawai_menyaring_berdasarkan_nama_atau_nip(): void
    {
        $unitKerja = UnitKerja::factory()->create();
        Pegawai::factory()->create(['nama' => 'Ahmad Fauzi', 'unit_kerja_id' => $unitKerja->id]);
        Pegawai::factory()->create(['nama' => 'Dewi Anggraini', 'unit_kerja_id' => $unitKerja->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/admin/pegawai?cari=ahmad')
            ->assertInertia(fn (Assert $page) => $page
                ->has('pegawai.data', 1)
                ->where('pegawai.data.0.nama', 'Ahmad Fauzi')
                ->etc());
    }

    #[Test]
    public function filter_status_foto_bekerja(): void
    {
        $unitKerja = UnitKerja::factory()->create();
        Pegawai::factory()->wajahTerdaftar()->create(['unit_kerja_id' => $unitKerja->id]);
        Pegawai::factory()->count(2)->create(['unit_kerja_id' => $unitKerja->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/admin/pegawai?status_foto=terdaftar')
            ->assertInertia(fn (Assert $page) => $page->has('pegawai.data', 1)->etc());

        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/admin/pegawai?status_foto=belum')
            ->assertInertia(fn (Assert $page) => $page->has('pegawai.data', 2)->etc());
    }

    #[Test]
    public function admin_upt_hanya_melihat_pegawai_unit_kerjanya(): void
    {
        $unitSendiri = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);
        $unitLain = UnitKerja::factory()->create(['kode' => 'BLK-MJK']);

        Pegawai::factory()->count(2)->create(['unit_kerja_id' => $unitSendiri->id]);
        Pegawai::factory()->count(3)->create(['unit_kerja_id' => $unitLain->id]);

        $this->actingAs(User::factory()->adminUpt($unitSendiri)->create())
            ->get('/admin/pegawai')
            ->assertInertia(fn (Assert $page) => $page
                ->has('pegawai.data', 2)
                ->where('dapat_sinkron', false)
                ->etc());
    }

    #[Test]
    public function admin_upt_tidak_boleh_memicu_sinkronisasi(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->adminUpt()->create())
            ->post('/admin/pegawai/sinkron')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    #[Test]
    public function sinkronisasi_manual_melaporkan_ringkasannya(): void
    {
        Http::fake([
            'worka.test/api/v1/absen/sync-status' => Http::response([
                'success' => true,
                'data' => ['total_pegawai_aktif' => 1, 'terakhir_diupdate' => null, 'checksum' => 'c1', 'server_time' => '2026-08-31T03:00:00Z'],
            ]),
            'worka.test/api/v1/absen/unit-kerja*' => Http::response([
                'success' => true,
                'data' => [['id' => 7, 'kode' => 'BLK-SBY', 'nama' => 'UPT BLK Surabaya', 'aktif' => true]],
            ]),
            'worka.test/api/v1/absen/pegawai*' => Http::response([
                'success' => true,
                'data' => [[
                    'nip' => '199001012020011001',
                    'nama' => 'Ahmad Fauzi',
                    'unit_kerja' => ['kode' => 'BLK-SBY', 'nama' => 'UPT BLK Surabaya'],
                    'jabatan' => 'Instruktur',
                    'status_aktif' => true,
                    'foto_tersedia' => true,
                    'updated_at' => '2026-08-30T10:00:00Z',
                ]],
                'meta' => ['last_page' => 1],
            ]),
        ]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/admin/pegawai/sinkron', ['penuh' => true])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('pegawai', ['nip' => '199001012020011001']);
    }

    #[Test]
    public function endpoint_status_menjawab_json(): void
    {
        Http::fake(['worka.test/*' => Http::response(['success' => true, 'data' => ['checksum' => 'c']])]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->getJson('/admin/pegawai/status')
            ->assertOk()
            ->assertJsonStructure([
                'terkonfigurasi', 'terhubung', 'api_url',
                'sinkron_terakhir_at', 'total_pegawai_worka', 'total_pegawai_lokal',
            ]);
    }

    #[Test]
    public function token_worka_disimpan_terenkripsi_dan_tidak_dikirim_ke_browser(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/admin/setting/worka', [
                'api_url' => 'http://worka.test',
                'api_token' => 'token-rahasia-sekali',
            ])
            ->assertSessionHas('sukses');

        $baris = Pengaturan::where('kunci', WorkaApiClient::KUNCI_TOKEN)->sole();

        $this->assertTrue($baris->terenkripsi);
        $this->assertNotSame('token-rahasia-sekali', $baris->nilai);
        $this->assertSame(
            'token-rahasia-sekali',
            app(PengaturanRepository::class)->ambil(WorkaApiClient::KUNCI_TOKEN),
        );

        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/admin/setting/worka')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Setting/Worka')
                ->where('token_terisi', true)
                ->missing('api_token')
                ->etc());
    }

    #[Test]
    public function token_kosong_saat_menyimpan_berarti_tidak_diubah(): void
    {
        app(PengaturanRepository::class)->simpan(WorkaApiClient::KUNCI_TOKEN, 'token-lama', terenkripsi: true);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/admin/setting/worka', ['api_url' => 'http://worka-baru.test', 'api_token' => '']);

        app(PengaturanRepository::class)->bersihkanIngatan();

        $this->assertSame('token-lama', app(PengaturanRepository::class)->ambil(WorkaApiClient::KUNCI_TOKEN));
        $this->assertSame('http://worka-baru.test', app(PengaturanRepository::class)->ambil(WorkaApiClient::KUNCI_URL));
    }

    #[Test]
    public function uji_koneksi_melaporkan_keberhasilan_beserta_rinciannya(): void
    {
        Http::fake([
            'worka.test/api/v1/absen/sync-status' => Http::response([
                'success' => true,
                'data' => ['total_pegawai_aktif' => 766, 'checksum' => 'c', 'server_time' => '2026-08-31T03:00:00Z'],
            ]),
        ]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->postJson('/admin/setting/worka/test')
            ->assertOk()
            ->assertJson(['sukses' => true, 'total_pegawai_aktif' => 766]);
    }

    #[Test]
    public function uji_koneksi_melaporkan_kegagalan_dengan_pesan_yang_dapat_dibaca(): void
    {
        Http::fake(['worka.test/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->postJson('/admin/setting/worka/test')
            ->assertOk()
            ->assertJson(['sukses' => false, 'http_status' => 401]);
    }

    #[Test]
    public function admin_upt_tidak_dapat_membuka_setting_worka(): void
    {
        $this->actingAs(User::factory()->adminUpt()->create())
            ->get('/admin/setting/worka')
            ->assertForbidden();
    }
}
