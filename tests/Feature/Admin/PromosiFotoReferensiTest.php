<?php

namespace Tests\Feature\Admin;

use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\AbsensiService;
use App\Services\FotoReferensiWajahService;
use App\Services\KioskService;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Foto absen dipromosikan menjadi foto referensi wajah (FR-PEG-05, revisi S29).
 *
 * Pendaftaran wajah massal tidak pernah selesai serentak: selalu ada pegawai
 * yang belum sempat difoto admin. Selama verifikasi wajah dimatikan, mereka
 * tetap mengabsen dengan kamera menyala, dan foto itu sudah menampilkan orang
 * yang benar — membiarkannya menganggur berarti pendaftaran wajah harus
 * diulang dari nol pada hari verifikasi dinyalakan.
 *
 * Yang dijaga berkas ini adalah SYARATNYA. Foto sembarangan yang telanjur
 * menjadi pembanding akan merusak pencocokan begitu verifikasi dinyalakan
 * kembali, dan kerusakannya baru ketahuan pada hari itu juga.
 */
class PromosiFotoReferensiTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'token-perangkat-uji';

    protected UnitKerja $unitKerja;

    protected EventAbsen $event;

    protected Pegawai $pegawai;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AbsensiService::DISK);

        $this->unitKerja = UnitKerja::factory()->create(['kode' => 'BLK-SBY']);

        $perangkat = Kiosk::factory()->diaktifkan(self::TOKEN)->create([
            'unit_kerja_id' => $this->unitKerja->id,
        ]);

        $this->event = EventAbsen::factory()->create([
            'tanggal' => '2026-09-07',
            'jam_mulai' => '07:30',
            'toleransi_menit' => 15,
        ]);
        $this->event->unitKerja()->attach($this->unitKerja);
        $this->gabungkanKeEvent($this->event, $perangkat);

        $this->pegawai = Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'unit_kerja_id' => $this->unitKerja->id,
            'wajah_terdaftar' => false,
        ]);

        $this->aturWajah(false);
        $this->travelTo('2026-09-07 07:35:00');
    }

    protected function aturWajah(bool $aktif): void
    {
        app(SettingAbsenService::class)->simpan(
            ['metode_wajah_aktif' => $aktif],
            User::factory()->superadmin()->create(),
        );
    }

    /** Deskriptor 128 dimensi, bentuk yang dihasilkan face-api di peramban. */
    protected function embedding(): array
    {
        return array_fill(0, FotoReferensiWajahService::DIMENSI_EMBEDDING, 0.1);
    }

    /** JPEG 1x1 piksel sebagai data URI. */
    protected function fotoUji(): string
    {
        return 'data:image/jpeg;base64,'.base64_encode(
            base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==')
        );
    }

    /**
     * @param  array<string, mixed>  $ubahan
     */
    protected function tap(array $ubahan = []): TestResponse
    {
        return $this->withCookie(KioskService::NAMA_COOKIE, self::TOKEN)
            ->post('/kiosk/event/absen', array_merge([
                'id_card' => '199001012020011001',
                'jenis' => 'datang',
                'metode' => 'manual',
                'foto' => $this->fotoUji(),
                'embedding' => $this->embedding(),
            ], $ubahan), ['Accept' => 'application/json']);
    }

    #[Test]
    public function foto_absen_menjadi_foto_referensi_pegawai_yang_belum_punya(): void
    {
        $this->tap()
            ->assertOk()
            ->assertJson(['data' => ['wajah_didaftarkan' => true]]);

        $pegawai = $this->pegawai->fresh();

        $this->assertTrue($pegawai->wajah_terdaftar);
        $this->assertNotNull($pegawai->foto_referensi_path);
        $this->assertCount(FotoReferensiWajahService::DIMENSI_EMBEDDING, $pegawai->embedding_wajah);

        Storage::disk(FotoReferensiWajahService::DISK)->assertExists($pegawai->foto_referensi_path);
    }

    #[Test]
    public function foto_absen_tetap_tersimpan_terpisah_sebagai_bukti_kehadiran(): void
    {
        // Disalin, bukan dipindahkan: foto absen harus tetap dapat dibuka dari
        // Rekap Absen setelah dipromosikan.
        $this->tap()->assertOk();

        $pegawai = $this->pegawai->fresh();
        $absensi = Absensi::sole();

        $this->assertNotSame($absensi->foto_path, $pegawai->foto_referensi_path);

        Storage::disk(AbsensiService::DISK)->assertExists($absensi->foto_path);
    }

    #[Test]
    public function tanpa_deskriptor_foto_tidak_pernah_dipromosikan(): void
    {
        /*
         * Deskriptor 128 dimensi adalah SATU-SATUNYA bukti bahwa peramban
         * menemukan tepat satu wajah pada foto ini — pemeriksaan yang sama
         * dengan alur pendaftaran manual. Tanpa itu, foto yang dipromosikan
         * bisa saja berisi ruangan kosong atau dua orang sekaligus.
         */
        $this->tap(['embedding' => null])
            ->assertOk()
            ->assertJson(['data' => ['wajah_didaftarkan' => false]]);

        $this->assertFalse($this->pegawai->fresh()->wajah_terdaftar);
    }

    #[Test]
    public function deskriptor_berdimensi_salah_ditolak_server(): void
    {
        // Bentuknya diperiksa ulang di server; peramban dapat dimanipulasi.
        $this->tap(['embedding' => array_fill(0, 64, 0.1)])
            ->assertOk()
            ->assertJson(['data' => ['wajah_didaftarkan' => false]]);

        $this->assertFalse($this->pegawai->fresh()->wajah_terdaftar);
    }

    #[Test]
    public function absen_tanpa_foto_tidak_dapat_dipromosikan(): void
    {
        $this->tap(['foto' => null])
            ->assertOk()
            ->assertJson(['data' => ['wajah_didaftarkan' => false]]);

        $this->assertFalse($this->pegawai->fresh()->wajah_terdaftar);
    }

    #[Test]
    public function foto_referensi_yang_sudah_ada_tidak_pernah_tertimpa(): void
    {
        /*
         * Foto referensi yang didaftarkan admin adalah hasil sesi pemotretan
         * yang disengaja — jauh lebih baik daripada tangkapan sekilas di depan
         * titik absen. Menimpanya diam-diam justru menurunkan mutu pembanding.
         */
        $this->pegawai->update([
            'wajah_terdaftar' => true,
            'foto_referensi_path' => 'foto-referensi/asli.jpg',
            'embedding_wajah' => $this->embedding(),
        ]);

        $this->tap()
            ->assertOk()
            ->assertJson(['data' => ['wajah_didaftarkan' => false]]);

        $this->assertSame('foto-referensi/asli.jpg', $this->pegawai->fresh()->foto_referensi_path);
    }

    #[Test]
    public function tidak_ada_promosi_ketika_verifikasi_wajah_menyala(): void
    {
        /*
         * Saat verifikasi menyala, skor yang menyertai tap berasal dari
         * pencocokan terhadap foto referensi — dan pegawai ini tidak punya
         * satu pun. Layar titik absen menolaknya sendiri sebelum mengirim
         * ("Wajah pegawai ini belum terdaftar"), tetapi server tidak dapat
         * mengandalkan itu: kiriman yang dirakit sendiri tetap membawa skor
         * yang tampak sah.
         *
         * Karena itu pagarnya di server bukan skor, melainkan settingnya.
         * Mempromosikan foto di sini berarti mendaftarkan wajah tanpa seorang
         * pun — manusia maupun mesin — pernah memastikan itu orang yang benar.
         */
        $this->aturWajah(true);

        $this->tap(['skor' => 95])
            ->assertOk()
            ->assertJson(['data' => ['wajah_didaftarkan' => false]]);

        $this->assertFalse($this->pegawai->fresh()->wajah_terdaftar);
    }

    #[Test]
    public function promosi_tercatat_pada_audit_trail_tanpa_pelaku(): void
    {
        /*
         * Tidak ada admin yang menekan tombol — justru karena itu jejaknya
         * penting: admin harus dapat menemukan pegawai mana saja yang foto
         * referensinya lahir dari absen, bukan dari sesi pendaftaran.
         */
        $this->tap()->assertOk();

        $this->assertDatabaseHas('log_aktivitas', [
            'user_id' => null,
            'subjek_type' => Pegawai::class,
            'subjek_id' => $this->pegawai->id,
        ]);
    }
}
