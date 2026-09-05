<?php

namespace Tests\Feature\Admin;

use App\Enums\JenisEvent;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\AbsensiService;
use App\Services\AbsenUmumService;
use App\Services\KioskService;
use App\Services\SettingAbsenService;
use App\Support\PengaturanRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dua cacat Absen Umum yang ditemukan saat uji coba Mode Terbuka (S28b).
 *
 * Keduanya lolos pengujian sebelumnya karena diuji dari sisi perangkat absen
 * saja — dan justru pada layar absen di peramban admin, serta pada sesi harian
 * yang lahir kembar, perilakunya berbeda.
 */
class FotoDanSesiAbsenUmumTest extends TestCase
{
    use RefreshDatabase;

    protected UnitKerja $upt;

    protected Pegawai $pegawai;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AbsensiService::DISK);

        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $this->upt = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $opd->id]);

        $this->pegawai = Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->upt->id,
        ]);

        // Yang diuji jalur pencatatannya, bukan pencocokan wajahnya.
        app(PengaturanRepository::class)->simpan(SettingAbsenService::KUNCI_WAJAH, '0');

        /*
         * Jam dipatok di dalam jendela "datang" (FR-SET-07). Sebelum jendela
         * itu ada, berkas ini menumpang jam berapa pun yang kebetulan berlaku
         * saat test dijalankan — dan itu berarti hasilnya bergantung pada pukul
         * berapa seseorang menjalankan `php artisan test`.
         */
        $this->travelTo('2026-09-07 07:35:00');
    }

    /** Jalur relatif beserta query-nya — konteks unit ada di sana. */
    protected function jalur(string $url): string
    {
        $bagian = parse_url($url);

        return $bagian['path'].(isset($bagian['query']) ? '?'.$bagian['query'] : '');
    }

    protected function fotoUji(): string
    {
        return 'data:image/jpeg;base64,'.base64_encode(
            base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==')
        );
    }

    /* ---------------------------------------------------------------------
     * Bug 1 — URL foto mengikuti pagar autentikasi pemanggilnya.
     * ------------------------------------------------------------------- */

    #[Test]
    public function foto_pada_layar_absen_admin_dapat_dibuka_admin(): void
    {
        /*
         * URL foto sempat dipatok ke rute /kiosk. Layar absen di peramban admin
         * tidak memegang device token, sehingga permintaannya dijawab 302 ke
         * layar aktivasi — di layar tampak sebagai ikon gambar rusak, bukan
         * sebagai kegagalan yang terbaca.
         */
        $admin = User::factory()->superadmin()->create();

        $jawaban = $this->actingAs($admin)
            ->post("/admin/kelola-absen/absen-umum/absen?unit_kerja_id={$this->upt->id}", [
                'id_card' => '199001012020011001',
                'jenis' => 'datang',
                'metode' => 'manual',
                'foto' => $this->fotoUji(),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $url = $jawaban->json('data.daftar_presensi.0.foto_url');

        $this->assertNotNull($url, 'Foto tersimpan tetapi tidak diberi URL.');
        $this->assertStringContainsString('/admin/kelola-absen/absen-umum/absen/', $url);

        // Dan benar-benar dapat diambil oleh admin yang sama.
        $this->actingAs($admin)
            ->get($this->jalur($url))
            ->assertOk();
    }

    #[Test]
    public function foto_pada_perangkat_absen_tetap_memakai_rute_kiosk(): void
    {
        /*
         * Perangkat ini melayani absen harian, bukan kegiatan, sehingga URL
         * fotonya harus jatuh ke grup rute mode umum. Sejak layar dipisah
         * (revisi S29) ada TIGA grup yang mungkin — /kiosk/event, /kiosk/umum,
         * dan /admin — dan memilih yang keliru menghasilkan gejala yang sama
         * dengan bug S28b: ikon gambar rusak, bukan kegagalan yang terbaca.
         */
        Kiosk::factory()->diaktifkan('token-uji')->create(['unit_kerja_id' => $this->upt->id]);

        $jawaban = $this->withCookie(KioskService::NAMA_COOKIE, 'token-uji')
            ->post('/kiosk/umum/absen', [
                'id_card' => '199001012020011001',
                'jenis' => 'datang',
                'metode' => 'manual',
                'foto' => $this->fotoUji(),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $url = $jawaban->json('data.daftar_presensi.0.foto_url');

        $this->assertStringContainsString('/kiosk/umum/absen/', $url);

        $this->withCookie(KioskService::NAMA_COOKIE, 'token-uji')
            ->get($this->jalur($url))
            ->assertOk();
    }

    #[Test]
    public function penarikan_presensi_juga_memakai_rute_pemanggilnya(): void
    {
        $admin = User::factory()->superadmin()->create();

        $this->actingAs($admin)
            ->post("/admin/kelola-absen/absen-umum/absen?unit_kerja_id={$this->upt->id}", [
                'id_card' => '199001012020011001',
                'jenis' => 'datang',
                'metode' => 'manual',
                'foto' => $this->fotoUji(),
            ], ['Accept' => 'application/json']);

        // Daftar yang ditarik berkala membawa URL yang sama bentuknya.
        $url = $this->actingAs($admin)
            ->get("/admin/kelola-absen/absen-umum/presensi?unit_kerja_id={$this->upt->id}", [
                'Accept' => 'application/json',
            ])
            ->assertOk()
            ->json('daftar_presensi.0.foto_url');

        $this->assertStringContainsString('/admin/kelola-absen/absen-umum/absen/', $url);
    }

    /* ---------------------------------------------------------------------
     * Bug 3 — zona waktu penilaian kehadiran.
     * ------------------------------------------------------------------- */

    #[Test]
    public function aplikasi_berjalan_pada_waktu_setempat_bukan_utc(): void
    {
        /*
         * `jam_mulai` event diisi admin dalam waktu setempat — "apel 07:30"
         * berarti 07:30 WIB. Bila aplikasi berjalan pada UTC, tap pukul 08:00
         * WIB tersimpan 01:00 dan dinilai tepat waktu terhadap ambang 07:30,
         * sehingga seluruh keterlambatan sebelum 14:30 WIB luput.
         */
        $this->assertNotSame('UTC', config('app.timezone'));
        $this->assertSame('+07:00', now()->format('P'));
    }

    /* ---------------------------------------------------------------------
     * Bug 2 — sesi harian tidak boleh lahir kembar.
     * ------------------------------------------------------------------- */

    #[Test]
    public function sesi_harian_kembar_ditolak_basis_data(): void
    {
        /*
         * Inilah yang membuat penolakan tap ganda tampak tidak bekerja: tap
         * pertama dan kedua jatuh ke sesi yang berbeda, sehingga kunci unik
         * (event, pegawai, jenis) tidak pernah bertabrakan.
         */
        $absenUmum = app(AbsenUmumService::class);

        $pertama = $absenUmum->buka($this->upt->id);

        // Meniru titik absen lain yang membuka sesi hari yang sama.
        $kembar = EventAbsen::factory()->make([
            'jenis' => JenisEvent::Umum,
            'kunci_sesi' => AbsenUmumService::kunci($this->upt->id, Carbon::today()),
            'tanggal' => Carbon::today()->toDateString(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        try {
            $kembar->save();
        } finally {
            $this->assertSame(1, EventAbsen::query()->umum()->count());
            $this->assertNotNull($pertama);
        }
    }

    #[Test]
    public function pembukaan_berulang_selalu_mengembalikan_sesi_yang_sama(): void
    {
        $absenUmum = app(AbsenUmumService::class);

        $ids = collect(range(1, 5))
            ->map(fn () => $absenUmum->buka($this->upt->id)->id)
            ->unique();

        $this->assertCount(1, $ids);
        $this->assertSame(1, EventAbsen::query()->umum()->count());
    }

    #[Test]
    public function tap_kedua_ditolak_pada_layar_absen_admin(): void
    {
        $admin = User::factory()->superadmin()->create();

        $kirim = fn () => $this->actingAs($admin)
            ->post("/admin/kelola-absen/absen-umum/absen?unit_kerja_id={$this->upt->id}", [
                'id_card' => '199001012020011001',
                'jenis' => 'datang',
                'metode' => 'manual',
            ], ['Accept' => 'application/json']);

        $kirim()->assertOk();

        $kirim()
            ->assertStatus(409)
            ->assertJson(['code' => 'SUDAH_ABSEN']);

        $this->assertSame(1, Absensi::query()->count());
    }

    #[Test]
    public function identifikasi_pada_layar_admin_menandai_yang_sudah_absen(): void
    {
        $admin = User::factory()->superadmin()->create();

        $this->actingAs($admin)
            ->post("/admin/kelola-absen/absen-umum/absen?unit_kerja_id={$this->upt->id}", [
                'id_card' => '199001012020011001',
                'jenis' => 'datang',
                'metode' => 'manual',
            ], ['Accept' => 'application/json'])
            ->assertOk();

        // Layar berhenti di sini pada tap berikutnya, sebelum kamera menyala.
        $this->actingAs($admin)
            ->post("/admin/kelola-absen/absen-umum/tap/identifikasi?unit_kerja_id={$this->upt->id}", [
                'id_card' => '199001012020011001',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['data' => ['sudah_absen' => ['pulang' => null]]])
            ->assertJsonPath('data.sudah_absen.datang', fn ($jam) => $jam !== null);
    }
}
