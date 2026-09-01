<?php

namespace Tests\Feature\Admin;

use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Laporan kehadiran per pegawai (FR-LAP-01 s.d. FR-LAP-03).
 */
class LaporanTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = '/admin/laporan';

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

    protected function eventPada(string $tanggal, ?UnitKerja $unit = null): EventAbsen
    {
        $event = EventAbsen::factory()->create(['tanggal' => $tanggal]);

        if ($unit !== null) {
            $event->unitKerja()->attach($unit);
        }

        return $event;
    }

    #[Test]
    public function tanpa_keterangan_dihitung_dari_event_yang_berlaku_baginya(): void
    {
        /*
         * FR-LAP-02. Tiga event untuk unitnya, pegawai hadir pada satu —
         * berarti dua tanpa keterangan. Angka ini mustahil didapat hanya
         * dengan menghitung baris absensi.
         */
        ['upt' => $upt] = $this->hirarki();
        $pegawai = Pegawai::factory()->create(['nama' => 'Ahmad Fauzi', 'unit_kerja_id' => $upt->id]);

        $hadir = $this->eventPada('2026-09-01', $upt);
        $this->eventPada('2026-09-02', $upt);
        $this->eventPada('2026-09-03', $upt);

        Absensi::factory()->create(['event_absen_id' => $hadir->id, 'pegawai_id' => $pegawai->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'?dari=2026-09-01&sampai=2026-09-30')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Laporan/Index')
                ->where('baris.0.event_berlaku', 3)
                ->where('baris.0.hadir', 1)
                ->where('baris.0.tanpa_keterangan', 2)
                ->etc());
    }

    #[Test]
    public function event_unit_lain_tidak_dihitung_sebagai_tanpa_keterangan(): void
    {
        // Pegawai tidak dapat dianggap mangkir dari event yang memang bukan
        // untuk unitnya.
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);

        $this->eventPada('2026-09-01', $upt);
        $this->eventPada('2026-09-02', $lain);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'?dari=2026-09-01&sampai=2026-09-30')
            ->assertInertia(fn (Assert $page) => $page
                ->where('baris.0.event_berlaku', 1)
                ->where('baris.0.tanpa_keterangan', 1)
                ->etc());

        $this->assertSame(1, Pegawai::whereKey($pegawai->id)->count());
    }

    #[Test]
    public function event_semua_unit_berlaku_untuk_setiap_pegawai(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);
        Pegawai::factory()->create(['unit_kerja_id' => $lain->id]);

        EventAbsen::factory()->semuaUnit()->create(['tanggal' => '2026-09-01']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'?dari=2026-09-01&sampai=2026-09-30')
            ->assertInertia(fn (Assert $page) => $page
                ->has('baris', 2)
                ->where('baris.0.event_berlaku', 1)
                ->where('baris.1.event_berlaku', 1)
                ->etc());
    }

    #[Test]
    public function event_unit_berlaku_untuk_pegawai_seksi_di_bawahnya(): void
    {
        // Cakupan event dinyatakan pada unit level teratas, sedangkan pegawai
        // menaut ke seksi.
        ['upt' => $upt, 'seksi' => $seksi] = $this->hirarki();
        Pegawai::factory()->create(['unit_kerja_id' => $seksi->id]);

        $this->eventPada('2026-09-01', $upt);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'?dari=2026-09-01&sampai=2026-09-30')
            ->assertInertia(fn (Assert $page) => $page
                ->where('baris.0.event_berlaku', 1)
                ->etc());
    }

    #[Test]
    public function terlambat_terhitung_terpisah_tetapi_tetap_bagian_dari_hadir(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);

        $pertama = $this->eventPada('2026-09-01', $upt);
        $kedua = $this->eventPada('2026-09-02', $upt);

        Absensi::factory()->create(['event_absen_id' => $pertama->id, 'pegawai_id' => $pegawai->id]);
        Absensi::factory()->terlambat()->create(['event_absen_id' => $kedua->id, 'pegawai_id' => $pegawai->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'?dari=2026-09-01&sampai=2026-09-30')
            ->assertInertia(fn (Assert $page) => $page
                ->where('baris.0.hadir', 2)
                ->where('baris.0.terlambat', 1)
                ->where('baris.0.tanpa_keterangan', 0)
                ->etc());
    }

    #[Test]
    public function event_di_luar_rentang_tidak_ikut_terhitung(): void
    {
        ['upt' => $upt] = $this->hirarki();
        Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);

        $this->eventPada('2026-08-20', $upt);
        $this->eventPada('2026-09-05', $upt);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'?dari=2026-09-01&sampai=2026-09-30')
            ->assertInertia(fn (Assert $page) => $page
                ->where('jumlah_event', 1)
                ->where('baris.0.event_berlaku', 1)
                ->etc());
    }

    #[Test]
    public function admin_upt_hanya_melihat_pegawai_unitnya(): void
    {
        ['upt' => $upt, 'lain' => $lain, 'seksi' => $seksi] = $this->hirarki();

        Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);
        Pegawai::factory()->create(['unit_kerja_id' => $seksi->id]);
        Pegawai::factory()->count(3)->create(['unit_kerja_id' => $lain->id]);

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->has('baris', 2)
                ->where('ringkasan.pegawai', 2)
                ->etc());
    }

    #[Test]
    public function penyaring_unit_tidak_dapat_melampaui_cakupan_peran(): void
    {
        // Admin UPT yang memaksa unit lain lewat kueri tetap tidak melihatnya.
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();

        Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);
        Pegawai::factory()->count(3)->create(['unit_kerja_id' => $lain->id]);

        $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL."?unit_kerja_id={$lain->id}")
            ->assertInertia(fn (Assert $page) => $page->has('baris', 0)->etc());
    }

    #[Test]
    public function rentang_terbalik_dibetulkan_bukan_ditolak(): void
    {
        ['upt' => $upt] = $this->hirarki();
        Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);
        $this->eventPada('2026-09-05', $upt);

        // Salah ketik urutan tanggal lebih mungkin daripada disengaja.
        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'?dari=2026-09-30&sampai=2026-09-01')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filter.dari', '2026-09-01')
                ->where('filter.sampai', '2026-09-30')
                ->where('jumlah_event', 1)
                ->etc());
    }

    #[Test]
    public function ekspor_csv_dapat_diunduh_dan_memakai_pemisah_titik_koma(): void
    {
        ['upt' => $upt] = $this->hirarki();
        Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'nama' => 'Ahmad Fauzi',
            'unit_kerja_id' => $upt->id,
        ]);
        $this->eventPada('2026-09-05', $upt);

        $jawaban = $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'/ekspor?dari=2026-09-01&sampai=2026-09-30')
            ->assertOk()
            ->assertDownload('laporan-kehadiran-20260901-sd-20260930.csv');

        $isi = $jawaban->streamedContent();

        // BOM UTF-8 supaya nama ber-diakritik tidak rusak di Excel.
        $this->assertStringStartsWith("\u{FEFF}", $isi);
        $this->assertStringContainsString('"NIP";"Nama";"Unit Kerja"', $isi);
        $this->assertStringContainsString('"199001012020011001";"Ahmad Fauzi"', $isi);
    }

    #[Test]
    public function ekspor_mengikuti_cakupan_peran(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();

        Pegawai::factory()->create(['nama' => 'Ahmad Fauzi', 'unit_kerja_id' => $upt->id]);
        Pegawai::factory()->create(['nama' => 'Citra Dewi', 'unit_kerja_id' => $lain->id]);

        $isi = $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL.'/ekspor')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Ahmad Fauzi', $isi);
        $this->assertStringNotContainsString('Citra Dewi', $isi);
    }

    #[Test]
    public function tanpa_rentang_laporan_memakai_bulan_berjalan(): void
    {
        $this->travelTo('2026-09-15 10:00:00');

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('filter.dari', '2026-09-01')
                ->where('filter.sampai', '2026-09-30')
                ->etc());
    }
}
