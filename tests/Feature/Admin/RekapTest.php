<?php

namespace Tests\Feature\Admin;

use App\Enums\StatusEvent;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\AbsensiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rekap Absen per event (FR-REK-01 s.d. FR-REK-03).
 */
class RekapTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = '/admin/kelola-absen/rekap';

    /**
     * @return array{upt: UnitKerja, lain: UnitKerja, seksi: UnitKerja}
     */
    protected function hirarki(): array
    {
        $opd = UnitKerja::factory()->create(['kode' => 'DISNAKERTRANS']);
        $upt = UnitKerja::factory()->create(['kode' => 'BLK-SGS', 'induk_id' => $opd->id]);
        $lain = UnitKerja::factory()->create(['kode' => 'BLK-SBY', 'induk_id' => $opd->id]);
        $seksi = UnitKerja::factory()->create(['kode' => 'BLK-SGS-TU', 'induk_id' => $upt->id]);

        return compact('upt', 'lain', 'seksi');
    }

    #[Test]
    public function rekap_menampilkan_kehadiran_beserta_ringkasannya(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create(['nama' => 'Apel Pagi']);
        $event->unitKerja()->attach($upt);

        $tepat = Pegawai::factory()->create(['nama' => 'Ahmad Fauzi', 'unit_kerja_id' => $upt->id]);
        $telat = Pegawai::factory()->create(['nama' => 'Dewi Anggraini', 'unit_kerja_id' => $upt->id]);

        Absensi::factory()->create(['event_absen_id' => $event->id, 'pegawai_id' => $tepat->id]);
        Absensi::factory()->terlambat()->create(['event_absen_id' => $event->id, 'pegawai_id' => $telat->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Rekap/Index')
                ->where('event.nama', 'Apel Pagi')
                ->has('rekap', 2)
                ->where('ringkasan.hadir', 2)
                ->where('ringkasan.tepat', 1)
                ->where('ringkasan.terlambat', 1)
                ->etc());
    }

    #[Test]
    public function datang_dan_pulang_menempati_satu_baris_rekap(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($upt);
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);

        Absensi::factory()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => $pegawai->id,
            'waktu' => now()->setTime(7, 35),
        ]);
        Absensi::factory()->pulang()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => $pegawai->id,
            'waktu' => now()->setTime(16, 5),
        ]);

        $rekap = app(AbsensiService::class)->rekap($event);

        $this->assertCount(1, $rekap);
        $this->assertSame('07:35', $rekap[0]['jam_masuk']);
        $this->assertSame('16:05', $rekap[0]['jam_pulang']);
    }

    #[Test]
    public function admin_upt_hanya_melihat_pegawainya_pada_event_semua_unit(): void
    {
        /*
         * FR-REK-02 — inti aturannya: aksesnya terbuka, isinya yang dibatasi.
         * Event bercakupan semua unit boleh dibuka Admin UPT, tetapi barisnya
         * hanya pegawai unitnya sendiri.
         */
        ['upt' => $upt, 'lain' => $lain, 'seksi' => $seksi] = $this->hirarki();
        $event = EventAbsen::factory()->semuaUnit()->create(['nama' => 'Apel Gabungan']);

        $milikSendiri = Pegawai::factory()->create(['nama' => 'Ahmad', 'unit_kerja_id' => $upt->id]);
        $dariSeksi = Pegawai::factory()->create(['nama' => 'Budi', 'unit_kerja_id' => $seksi->id]);
        $milikLain = Pegawai::factory()->create(['nama' => 'Citra', 'unit_kerja_id' => $lain->id]);

        foreach ([$milikSendiri, $dariSeksi, $milikLain] as $orang) {
            Absensi::factory()->create(['event_absen_id' => $event->id, 'pegawai_id' => $orang->id]);
        }

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL."?event_absen_id={$event->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Unit sendiri beserta seksi di bawahnya, bukan unit lain.
                ->has('rekap', 2)
                ->where('ringkasan.hadir', 2)
                ->etc());
    }

    #[Test]
    public function admin_upt_tidak_dapat_membuka_rekap_event_unit_lain(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($lain);

        // Event yang tidak menyentuh unitnya sama sekali tidak dapat dipilih;
        // halaman jatuh ke keadaan tanpa event.
        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL."?event_absen_id={$event->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('event', null)->etc());
    }

    #[Test]
    public function endpoint_data_menolak_event_di_luar_cakupan(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($lain);

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->getJson(self::URL."/{$event->id}/data")
            ->assertForbidden();
    }

    #[Test]
    public function endpoint_data_membawa_rekap_terkini_dan_status_event(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($upt);

        Absensi::factory()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => Pegawai::factory()->create([
                'nama' => 'Ahmad Fauzi',
                'unit_kerja_id' => $upt->id,
            ])->id,
        ]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->getJson(self::URL."/{$event->id}/data")
            ->assertOk()
            ->assertJson([
                'status' => 'aktif',
                'ringkasan' => ['hadir' => 1],
                'rekap' => [['nama' => 'Ahmad Fauzi']],
            ]);
    }

    #[Test]
    public function halaman_memilih_event_terbaru_bila_tidak_disebutkan(): void
    {
        ['upt' => $upt] = $this->hirarki();

        $lama = EventAbsen::factory()->ditutup()->create([
            'nama' => 'Apel Pekan Lalu',
            'tanggal' => '2026-08-31',
        ]);
        $lama->unitKerja()->attach($upt);

        $terbaru = EventAbsen::factory()->ditutup()->create([
            'nama' => 'Apel Hari Ini',
            'tanggal' => '2026-09-07',
        ]);
        $terbaru->unitKerja()->attach($upt);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page->where('event.nama', 'Apel Hari Ini')->etc());
    }

    #[Test]
    public function tanpa_event_sama_sekali_halaman_tetap_terbuka(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('event', null)
                ->has('rekap', 0)
                ->etc());
    }

    #[Test]
    public function foto_absen_admin_terbatas_pada_cakupan_unit(): void
    {
        // NFR-04: route foto panel admin dibatasi peran dan cakupannya,
        // bukan perangkat yang sedang melayani event.
        Storage::fake(AbsensiService::DISK);

        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $path = 'foto-absen/uji.jpg';

        Storage::disk(AbsensiService::DISK)->put($path, 'biner-jpeg');

        $absensi = Absensi::factory()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => Pegawai::factory()->create(['unit_kerja_id' => $lain->id])->id,
            'foto_path' => $path,
        ]);

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get("/admin/absensi/{$absensi->id}/foto")
            ->assertForbidden();

        $this->actingAs(User::factory()->superadmin()->create())
            ->get("/admin/absensi/{$absensi->id}/foto")
            ->assertOk();
    }

    #[Test]
    public function foto_absen_tertutup_bagi_tamu(): void
    {
        $absensi = Absensi::factory()->create(['foto_path' => 'foto-absen/uji.jpg']);

        $this->get("/admin/absensi/{$absensi->id}/foto")->assertRedirect('/masuk');
    }

    #[Test]
    public function rekap_dapat_diunduh_sebagai_csv_dan_pdf(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create(['nama' => 'Apel Pagi', 'tanggal' => '2026-09-07']);
        $event->unitKerja()->attach($upt);

        Absensi::factory()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => Pegawai::factory()->create([
                'nama' => 'Ahmad Fauzi',
                'unit_kerja_id' => $upt->id,
            ])->id,
        ]);

        $admin = User::factory()->superadmin()->create();

        $csv = $this->actingAs($admin)
            ->get(self::URL."/{$event->id}/ekspor")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('"NIP";"Nama"', $csv);
        $this->assertStringContainsString('Ahmad Fauzi', $csv);

        $pdf = $this->actingAs($admin)
            ->get(self::URL."/{$event->id}/ekspor?format=pdf")
            ->assertOk()
            ->getContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    #[Test]
    public function ekspor_rekap_mengikuti_cakupan_peran(): void
    {
        // FR-REK-02 berlaku pada berkas unduhan juga, bukan hanya di layar.
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $event = EventAbsen::factory()->semuaUnit()->create();

        foreach ([['Ahmad', $upt], ['Citra', $lain]] as [$nama, $unit]) {
            Absensi::factory()->create([
                'event_absen_id' => $event->id,
                'pegawai_id' => Pegawai::factory()->create([
                    'nama' => $nama,
                    'unit_kerja_id' => $unit->id,
                ])->id,
            ]);
        }

        $isi = $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL."/{$event->id}/ekspor")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Ahmad', $isi);
        $this->assertStringNotContainsString('Citra', $isi);
    }

    #[Test]
    public function ekspor_rekap_ditolak_untuk_event_di_luar_cakupan(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($lain);

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL."/{$event->id}/ekspor")
            ->assertForbidden();
    }

    #[Test]
    public function event_yang_sudah_ditutup_tetap_dapat_direkap(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $event = EventAbsen::factory()->create();
        $event->unitKerja()->attach($upt);

        Absensi::factory()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => Pegawai::factory()->create(['unit_kerja_id' => $upt->id])->id,
        ]);

        $event->update(['status' => StatusEvent::Ditutup, 'ditutup_pada' => now()]);

        // Rekap adalah arsip; menutup entry tidak menghilangkan datanya.
        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL."?event_absen_id={$event->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('event.status', 'ditutup')
                ->has('rekap', 1)
                ->etc());
    }
}
