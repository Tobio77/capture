<?php

namespace Tests\Feature;

use App\Enums\JenisAbsen;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\AbsensiService;
use App\Services\AbsenUmumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Jam Masuk dan Jam Pulang berasal dari satu pivot yang sama.
 *
 * Absensi menyimpan dua BARIS per orang — satu datang, satu pulang — sedangkan
 * seluruh rekap menampilkannya sebagai satu BARIS dengan dua kolom. Perubahan
 * bentuk itu terjadi di satu tempat saja, {@see AbsensiService::rekap()}, dan
 * berkas ini yang menjaganya tetap begitu: halaman yang menghitung sendiri
 * lambat laun akan berbeda hasil, dan perbedaan pada jam kehadiran adalah
 * perbedaan pada bukti.
 */
class PivotJamAbsenTest extends TestCase
{
    use RefreshDatabase;

    protected UnitKerja $upt;

    protected Pegawai $pegawai;

    protected function setUp(): void
    {
        parent::setUp();

        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $this->upt = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $opd->id]);

        $this->pegawai = Pegawai::factory()->create([
            'nama' => 'Ahmad Fauzi',
            'unit_kerja_id' => $this->upt->id,
        ]);
    }

    /**
     * Catat sepasang absen datang & pulang pada sebuah event.
     */
    protected function isiKehadiran(EventAbsen $event, string $masuk, string $pulang): void
    {
        Absensi::factory()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => $this->pegawai->id,
            'jenis' => JenisAbsen::Datang,
            'waktu' => $event->tanggal->toDateString().' '.$masuk,
        ]);

        Absensi::factory()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => $this->pegawai->id,
            'jenis' => JenisAbsen::Pulang,
            'waktu' => $event->tanggal->toDateString().' '.$pulang,
            'status_ketepatan' => null,
        ]);
    }

    #[Test]
    public function pivot_menggabungkan_dua_baris_absen_menjadi_satu_baris_rekap(): void
    {
        $event = EventAbsen::factory()->create(['tanggal' => '2026-09-07']);
        $event->unitKerja()->attach($this->upt);

        $this->isiKehadiran($event, '07:16:00', '15:04:00');

        $rekap = app(AbsensiService::class)->rekap($event);

        $this->assertCount(1, $rekap);
        $this->assertSame('07:16', $rekap[0]['jam_masuk']);
        $this->assertSame('15:04', $rekap[0]['jam_pulang']);
    }

    #[Test]
    public function kolom_pulang_kosong_tetap_ada_ketika_belum_absen_pulang(): void
    {
        // Kunci `jam_pulang` harus selalu ada — halaman menampilkan "—" untuk
        // nilai null, tetapi kunci yang hilang membuat kolomnya lenyap.
        $event = EventAbsen::factory()->create(['tanggal' => '2026-09-07']);
        $event->unitKerja()->attach($this->upt);

        Absensi::factory()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => $this->pegawai->id,
            'jenis' => JenisAbsen::Datang,
            'waktu' => '2026-09-07 07:16:00',
        ]);

        $rekap = app(AbsensiService::class)->rekap($event);

        $this->assertArrayHasKey('jam_masuk', $rekap[0]);
        $this->assertArrayHasKey('jam_pulang', $rekap[0]);
        $this->assertNull($rekap[0]['jam_pulang']);
    }

    #[Test]
    public function rekap_per_event_menampilkan_kedua_jam(): void
    {
        $event = EventAbsen::factory()->create(['nama' => 'Apel Pagi', 'tanggal' => '2026-09-07']);
        $event->unitKerja()->attach($this->upt);

        $this->isiKehadiran($event, '07:16:00', '15:04:00');

        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/admin/kelola-absen/rekap')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rekap.0.jam_masuk', '07:16')
                ->where('rekap.0.jam_pulang', '15:04')
                ->etc());
    }

    #[Test]
    public function absen_umum_menampilkan_kedua_jam_dari_pivot_yang_sama(): void
    {
        $sesi = app(AbsenUmumService::class)->buka($this->upt->id);

        $this->isiKehadiran($sesi, '07:16:00', '15:04:00');

        $this->actingAs(User::factory()->superadmin()->create())
            ->get("/admin/kelola-absen/absen-umum?unit_kerja_id={$this->upt->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('baris.0.jam_masuk', '07:16')
                ->where('baris.0.jam_pulang', '15:04')
                ->etc());
    }

    #[Test]
    public function daftar_presensi_titik_absen_memakai_pivot_yang_sama(): void
    {
        // Layar titik absen menampilkan kolom Masuk dan Pulang juga; bila ia
        // menghitung sendiri, angkanya dapat berbeda dari rekap admin untuk
        // event yang sama.
        $event = EventAbsen::factory()->create(['tanggal' => '2026-09-07']);
        $event->unitKerja()->attach($this->upt);

        $this->isiKehadiran($event, '07:16:00', '15:04:00');

        $absensi = app(AbsensiService::class);
        $presensi = $absensi->daftarPresensi($event);
        $rekap = $absensi->rekap($event);

        $this->assertCount(1, $presensi);
        $this->assertSame($rekap[0]['jam_masuk'], $presensi[0]['jam_masuk']);
        $this->assertSame($rekap[0]['jam_pulang'], $presensi[0]['jam_pulang']);
    }
}
