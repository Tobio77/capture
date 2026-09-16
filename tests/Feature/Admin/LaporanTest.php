<?php

namespace Tests\Feature\Admin;

use App\Enums\PeranPengguna;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Pegawai;
use App\Models\RiwayatLaporan;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\Laporan\LaporanResmiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

    /**
     * Catat kehadiran sepasang datang–pulang pada sebuah event.
     *
     * Dibutuhkan hampir setiap uji ekspor sejak berkas unduhan memuat RINCIAN
     * per sesi absen alih-alih agregat: pegawai yang tidak punya satu pun tap
     * tidak menghasilkan baris apa pun di sana. Angka ketidakhadirannya tetap
     * terbaca pada tabel di layar dan pada lembar PDF, yang keduanya masih
     * memakai agregat.
     */
    protected function hadir(EventAbsen $event, Pegawai $pegawai): void
    {
        Absensi::factory()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => $pegawai->id,
            'waktu' => $event->tanggal->copy()->setTime(7, 31),
        ]);

        Absensi::factory()->pulang()->create([
            'event_absen_id' => $event->id,
            'pegawai_id' => $pegawai->id,
            'waktu' => $event->tanggal->copy()->setTime(16, 4),
        ]);
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
                ->where('baris.data.0.event_berlaku', 3)
                ->where('baris.data.0.hadir', 1)
                ->where('baris.data.0.tanpa_keterangan', 2)
                ->etc());
    }

    #[Test]
    public function setiap_event_berlaku_bagi_pegawai_unit_mana_pun(): void
    {
        /*
         * Sejak S49 tidak ada lagi event "milik" sebuah unit: setiap kegiatan
         * berlaku bagi seluruh dinas, sehingga pegawai unit mana pun yang
         * tidak hadir terhitung tanpa keterangan padanya. Sebelum perubahan
         * itu, kasus ini justru menguji kebalikannya — pegawai tidak boleh
         * dianggap mangkir dari event yang bukan untuk unitnya.
         */
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        $pegawai = Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);

        $this->eventPada('2026-09-01', $upt);
        $this->eventPada('2026-09-02', $lain);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'?dari=2026-09-01&sampai=2026-09-30')
            ->assertInertia(fn (Assert $page) => $page
                ->where('baris.data.0.pegawai_id', $pegawai->id)
                ->where('baris.data.0.event_berlaku', 2)
                ->where('baris.data.0.tanpa_keterangan', 2)
                ->etc());
    }

    #[Test]
    public function event_semua_unit_berlaku_untuk_setiap_pegawai(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);
        Pegawai::factory()->create(['unit_kerja_id' => $lain->id]);

        EventAbsen::factory()->create(['tanggal' => '2026-09-01']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'?dari=2026-09-01&sampai=2026-09-30')
            ->assertInertia(fn (Assert $page) => $page
                ->has('baris.data', 2)
                ->where('baris.data.0.event_berlaku', 1)
                ->where('baris.data.1.event_berlaku', 1)
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
                ->where('baris.data.0.event_berlaku', 1)
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
                ->where('baris.data.0.hadir', 2)
                ->where('baris.data.0.terlambat', 1)
                ->where('baris.data.0.tanpa_keterangan', 0)
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
                ->where('baris.data.0.event_berlaku', 1)
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
                ->has('baris.data', 2)
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
            ->assertInertia(fn (Assert $page) => $page->has('baris.data', 0)->etc());
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
        $pegawai = Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'nama' => 'Ahmad Fauzi',
            'unit_kerja_id' => $upt->id,
        ]);
        $this->hadir($this->eventPada('2026-09-05', $upt), $pegawai);

        $jawaban = $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'/ekspor?dari=2026-09-01&sampai=2026-09-30')
            ->assertOk()
            ->assertDownload('laporan-kehadiran-20260901-sd-20260930.csv');

        $isi = $jawaban->streamedContent();

        // BOM UTF-8 supaya nama ber-diakritik tidak rusak di Excel.
        $this->assertStringStartsWith("\u{FEFF}", $isi);
        $this->assertStringContainsString('"NIP";"Nama";"Unit Kerja";"Tanggal"', $isi);
        $this->assertStringContainsString('"199001012020011001";"Ahmad Fauzi"', $isi);

        // Inti permintaannya: jam yang tercatat selalu ikut pada unduhan.
        $this->assertStringContainsString('"05-09-2026"', $isi);
        $this->assertStringContainsString('"07:31";"16:04"', $isi);
    }

    #[Test]
    public function ekspor_csv_menetralkan_nilai_yang_menyerupai_formula(): void
    {
        // CWE-1236 — CSV/Formula Injection: nama pegawai berasal dari
        // sinkronisasi WORKA, kolom yang tidak sepenuhnya di bawah kendali
        // aplikasi ini. Nilai yang diawali "=" akan dieksekusi sebagai
        // formula begitu berkasnya dibuka Excel, kecuali dinetralkan dulu.
        ['upt' => $upt] = $this->hirarki();
        $pegawai = Pegawai::factory()->create([
            'nama' => '=HYPERLINK("http://penyerang.test","klik di sini")',
            'unit_kerja_id' => $upt->id,
        ]);
        $this->hadir($this->eventPada('2026-09-05', $upt), $pegawai);

        $isi = $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'/ekspor?dari=2026-09-01&sampai=2026-09-30')
            ->assertOk()
            ->streamedContent();

        // Dibubuhi apostrof di depan "=" — Excel membacanya sebagai teks,
        // bukan formula. Isi kalimatnya sendiri tetap utuh, hanya dijaga
        // tidak lagi dianggap awal formula.
        $this->assertStringContainsString(
            '"\'=HYPERLINK(""http://penyerang.test"",""klik di sini"")"',
            $isi,
        );
        $this->assertStringNotContainsString(
            '"=HYPERLINK("http://penyerang.test","klik di sini")"',
            $isi,
        );
    }

    #[Test]
    public function ekspor_mengikuti_cakupan_peran(): void
    {
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();

        $event = $this->eventPada(now()->toDateString());

        $this->hadir($event, Pegawai::factory()->create([
            'nama' => 'Ahmad Fauzi',
            'unit_kerja_id' => $upt->id,
        ]));
        $this->hadir($event, Pegawai::factory()->create([
            'nama' => 'Citra Dewi',
            'unit_kerja_id' => $lain->id,
        ]));

        $isi = $this->actingAs(User::factory()->adminUpt($upt)->create())
            ->get(self::URL.'/ekspor')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Ahmad Fauzi', $isi);
        $this->assertStringNotContainsString('Citra Dewi', $isi);
    }

    #[Test]
    public function ekspor_pdf_menghasilkan_berkas_pdf(): void
    {
        ['upt' => $upt] = $this->hirarki();
        Pegawai::factory()->create(['nama' => 'Ahmad Fauzi', 'unit_kerja_id' => $upt->id]);
        $this->eventPada('2026-09-05', $upt);

        $jawaban = $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'/ekspor?format=pdf&dari=2026-09-01&sampai=2026-09-30')
            ->assertOk()
            ->assertDownload('laporan-kehadiran-20260901-sd-20260930.pdf');

        // Tanda tangan berkas PDF, bukan sekadar nama berkasnya.
        $this->assertStringStartsWith('%PDF-', $jawaban->getContent());
    }

    #[Test]
    public function ekspor_memuat_seluruh_baris_bukan_hanya_satu_halaman(): void
    {
        /*
         * Layar dibatasi 25 baris per halaman; lampiran administratif yang
         * ikut terpotong halaman tidak ada gunanya.
         */
        ['upt' => $upt] = $this->hirarki();
        $event = $this->eventPada(now()->toDateString());

        foreach (Pegawai::factory()->count(30)->create(['unit_kerja_id' => $upt->id]) as $orang) {
            $this->hadir($event, $orang);
        }

        $isi = $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'/ekspor')
            ->assertOk()
            ->streamedContent();

        // 30 baris data + 1 baris judul.
        $this->assertSame(31, substr_count(trim($isi), "\r\n") + 1);
    }

    #[Test]
    public function pencarian_menyaring_baris_laporan(): void
    {
        ['upt' => $upt] = $this->hirarki();
        Pegawai::factory()->create(['nama' => 'Ahmad Fauzi', 'unit_kerja_id' => $upt->id]);
        Pegawai::factory()->create(['nama' => 'Dewi Anggraini', 'unit_kerja_id' => $upt->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'?cari=dewi')
            ->assertInertia(fn (Assert $page) => $page
                ->has('baris.data', 1)
                ->where('baris.data.0.nama', 'Dewi Anggraini')
                ->etc());
    }

    #[Test]
    public function laporan_terpaginasi(): void
    {
        ['upt' => $upt] = $this->hirarki();
        Pegawai::factory()->count(30)->create(['unit_kerja_id' => $upt->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->has('baris.data', 25)
                ->where('baris.total', 30)
                ->where('baris.last_page', 2)
                // Ringkasan mengikuti hasil penyaringan, bukan halaman.
                ->where('ringkasan.pegawai', 30)
                ->etc());
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

    #[Test]
    public function kolom_unit_menyebut_unit_level_teratas_bukan_seksi(): void
    {
        /*
         * Pegawai menaut ke seksi, sedangkan yang ditawarkan penyaring di atas
         * tabel adalah UPT/bidang. Sebelum perubahan ini kolomnya menyebut
         * seksi, sehingga tabel yang disaring "UPT BLK Singosari" memuat
         * baris-baris bertuliskan "BLK-SGS-TU" — dan pembaca mengira
         * penyaringnya tidak bekerja.
         */
        ['upt' => $upt, 'seksi' => $seksi] = $this->hirarki();

        Pegawai::factory()->create(['nama' => 'Ahmad Fauzi', 'unit_kerja_id' => $seksi->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('baris.data.0.unit_kerja', $upt->nama)
                ->etc());
    }

    #[Test]
    public function menggulung_nama_unit_tidak_menggulung_perhitungannya(): void
    {
        /*
         * Penjaga dari kekeliruan yang paling mahal pada perubahan ini: yang
         * digulung HANYA nama yang tertulis, bukan `unit_kerja_id` yang
         * dipakai menghitung. Kalau sampai id-nya ikut digulung, laporan akan
         * terlihat rapi — nama unitnya sudah benar — sambil kehilangan pegawai
         * yang bertaut ke seksi, dan salah menghitung event yang berlaku.
         *
         * Dua hal yang dijaga sekaligus: pegawai seksi tetap MASUK ke laporan
         * unit induknya, dan event yang dinyatakan bagi induknya tetap
         * terhitung berlaku baginya.
         */
        ['upt' => $upt, 'seksi' => $seksi] = $this->hirarki();

        $diInduk = Pegawai::factory()->create(['nama' => 'Ahmad Fauzi', 'unit_kerja_id' => $upt->id]);
        $diSeksi = Pegawai::factory()->create(['nama' => 'Budi Santoso', 'unit_kerja_id' => $seksi->id]);

        $this->eventPada('2026-09-01', $upt);
        $this->eventPada('2026-09-02', $upt);

        $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL."?dari=2026-09-01&sampai=2026-09-30&unit_kerja_id={$upt->id}")
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($diInduk, $diSeksi, $upt) {
                $baris = collect($page->toArray()['props']['baris']['data'])
                    ->keyBy('pegawai_id');

                // Pegawai seksi tidak boleh hilang dari laporan induknya.
                $this->assertCount(2, $baris);
                $this->assertTrue($baris->has($diSeksi->id), 'Pegawai seksi harus ikut terhitung.');

                // Keduanya menuliskan nama unit yang sama…
                $this->assertSame($upt->nama, $baris[$diInduk->id]['unit_kerja']);
                $this->assertSame($upt->nama, $baris[$diSeksi->id]['unit_kerja']);

                // …dan keduanya sama-sama terkena dua event milik induknya.
                $this->assertSame(2, $baris[$diInduk->id]['event_berlaku']);
                $this->assertSame(2, $baris[$diSeksi->id]['event_berlaku']);
            });
    }

    /* ---------------------------------------------------------------------
     * Bagian A — checklist kolom & Excel sungguhan.
     * ------------------------------------------------------------------- */

    #[Test]
    public function checklist_kolom_menyaring_kolom_csv(): void
    {
        ['upt' => $upt] = $this->hirarki();
        $pegawai = Pegawai::factory()->create([
            'nip' => '199001012020011001',
            'nama' => 'Ahmad Fauzi',
            'unit_kerja_id' => $upt->id,
        ]);
        $this->hadir($this->eventPada('2026-09-05', $upt), $pegawai);

        $isi = $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'/ekspor?dari=2026-09-01&sampai=2026-09-30&kolom[]=metode')
            ->assertOk()
            ->streamedContent();

        /*
         * NIP, Nama, Tanggal, Jam Masuk, dan Jam Pulang tetap ikut walau tidak
         * diminta (lihat KOLOM_WAJIB): tanpa keduanya yang pertama baris tidak
         * dapat dikenali, dan tanpa jam berkasnya berhenti menjadi laporan
         * kehadiran. Kolom lain yang tidak dicentang tidak ikut.
         */
        $this->assertStringContainsString(
            '"NIP";"Nama";"Tanggal";"Jam Masuk";"Jam Pulang";"Metode"',
            $isi,
        );
        $this->assertStringNotContainsString('Unit Kerja', $isi);
        $this->assertStringNotContainsString('Alamat IP', $isi);
    }

    #[Test]
    public function kolom_yang_tidak_dikenal_diabaikan(): void
    {
        ['upt' => $upt] = $this->hirarki();
        Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);

        // 'sql_injection_coba' bukan kunci kolom yang sah — harus dilewati
        // dengan tenang, bukan membocorkan kolom lain lewat nama sembarangan.
        $isi = $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'/ekspor?dari=2026-09-01&sampai=2026-09-30&kolom[]=sql_injection_coba')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('"NIP";"Nama"', $isi);
    }

    #[Test]
    public function ekspor_excel_menetralkan_nilai_yang_menyerupai_formula(): void
    {
        // Perlindungan yang sama seperti CSV (CWE-1236), sisi .xlsx: kolom
        // ditulis lewat PhpSpreadsheet (TabelDataExport), jalur kode yang
        // sama sekali berbeda dari perakitan string CSV.
        ['upt' => $upt] = $this->hirarki();
        $pegawai = Pegawai::factory()->create(['nama' => '=1+1', 'unit_kerja_id' => $upt->id]);
        $this->hadir($this->eventPada('2026-09-05', $upt), $pegawai);

        $jawaban = $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'/ekspor?format=xlsx&dari=2026-09-01&sampai=2026-09-30')
            ->assertOk();

        $sementara = tempnam(sys_get_temp_dir(), 'laporan-uji').'.xlsx';
        file_put_contents($sementara, $this->isiBerkasUnduhan($jawaban));
        $sheet = IOFactory::load($sementara)->getActiveSheet();
        unlink($sementara);

        $ditemukan = false;

        for ($baris = 1; $baris <= $sheet->getHighestRow() && ! $ditemukan; $baris++) {
            for ($kolom = 'A'; $kolom <= $sheet->getHighestColumn(); $kolom++) {
                $nilai = $sheet->getCell("{$kolom}{$baris}")->getValue();

                if (is_string($nilai) && str_contains($nilai, '1+1')) {
                    // Dibubuhi apostrof di depan, bukan tersimpan sebagai
                    // formula yang akan dihitung ulang Excel saat dibuka.
                    $this->assertSame("'=1+1", $nilai);
                    $ditemukan = true;
                }
            }
        }

        $this->assertTrue($ditemukan, 'Nilai nama pegawai tidak ditemukan pada sheet.');
    }

    #[Test]
    public function ekspor_excel_menghasilkan_berkas_xlsx_sungguhan(): void
    {
        ['upt' => $upt] = $this->hirarki();
        Pegawai::factory()->create(['nama' => 'Ahmad Fauzi', 'unit_kerja_id' => $upt->id]);
        $this->eventPada('2026-09-05', $upt);

        $jawaban = $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'/ekspor?format=xlsx&dari=2026-09-01&sampai=2026-09-30')
            ->assertOk()
            ->assertDownload('laporan-kehadiran-20260901-sd-20260930.xlsx');

        // Tanda tangan ZIP: .xlsx adalah arsip ZIP, bukan CSV berlabel palsu.
        $this->assertStringStartsWith("PK\x03\x04", $this->isiBerkasUnduhan($jawaban));
    }

    #[Test]
    public function ekspor_excel_menulis_nip_sebagai_teks_bukan_notasi_ilmiah(): void
    {
        // NIP 18 digit yang dibiarkan tertebak sendiri oleh PhpSpreadsheet
        // ditulis sebagai sel NUMERIK — Excel menampilkannya sebagai
        // "1,98E+17", dan nilai float yang tersimpan sungguhan kehilangan
        // digit di belakang (presisi float hanya ~15-17 digit signifikan).
        // Bukan cuma salah tampil: NIP-nya sendiri berubah begitu dibuka.
        ['upt' => $upt] = $this->hirarki();
        $pegawai = Pegawai::factory()->create([
            'nip' => '198001012020011001',
            'nama' => 'Ahmad Fauzi',
            'unit_kerja_id' => $upt->id,
        ]);
        $this->hadir($this->eventPada('2026-09-05', $upt), $pegawai);

        $jawaban = $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'/ekspor?format=xlsx&dari=2026-09-01&sampai=2026-09-30')
            ->assertOk();

        $sementara = tempnam(sys_get_temp_dir(), 'laporan-nip-uji').'.xlsx';
        file_put_contents($sementara, $this->isiBerkasUnduhan($jawaban));
        $sheet = IOFactory::load($sementara)->getActiveSheet();
        unlink($sementara);

        $selNip = $sheet->getCell('A2');
        $this->assertSame(DataType::TYPE_STRING, $selNip->getDataType());
        $this->assertSame('198001012020011001', $selNip->getValue());
    }

    #[Test]
    public function ekspor_pdf_tidak_gagal_karena_kehabisan_memori_pada_data_besar(): void
    {
        // Regresi nyata: DomPDF menghabiskan ratusan MB untuk tabel beberapa
        // ratus baris (terukur ~294MB pada data produksi 666 pegawai),
        // jauh melampaui batas bawaan PHP (128M) — sebelum diperbaiki,
        // permintaan ini gagal total dengan fatal error kehabisan memori di
        // tengah render, dan peramban hanya melihat unduhan yang tidak
        // pernah selesai. EksporService::unduhPdf() sekarang menaikkan
        // memory_limit di sekeliling render (lihat denganMemoriBesar()).
        // Sengaja TIDAK meng-ini_set() memory_limit secara manual di sini:
        // 128M sudah default PHP CLI (dikonfirmasi tanpa override apa pun di
        // phpunit.xml) — persis ambang yang sama dengan permintaan HTTP
        // sungguhan. Test ini murni menumpangi kondisi nyata itu.
        ['upt' => $upt] = $this->hirarki();
        Pegawai::factory()->count(300)->create(['unit_kerja_id' => $upt->id]);
        $this->eventPada('2026-09-05', $upt);

        $jawaban = $this->actingAs(User::factory()->superadmin()->create())
            ->get(self::URL.'/ekspor?format=pdf&dari=2026-09-01&sampai=2026-09-30')
            ->assertOk();

        $this->assertStringStartsWith('%PDF-', $jawaban->getContent());
    }

    /* ---------------------------------------------------------------------
     * Bagian B — Generate Laporan Resmi (FR-LAP-04, revisi antrian).
     *
     * generate() tidak lagi mengembalikan berkas: ia membuat baris Riwayat
     * Laporan lalu mengantrekan BuatLaporanResmiJob lewat afterResponse().
     * Dalam pengujian, Illuminate\Foundation\Testing\Concerns\MakesHttpRequests
     * memanggil $kernel->terminate() secara sinkron sesudah setiap request
     * (lihat call()), sehingga job afterResponse() ini betul-betul berjalan
     * di sini — bukan cuma tersimpan sebagai baris "antre" selamanya seperti
     * yang semula dikeluhkan pengguna di lingkungan tanpa queue worker.
     * ------------------------------------------------------------------- */

    #[Test]
    public function format_di_luar_daftar_yang_dikenal_ditolak_bukan_ikut_dijadikan_nama_berkas(): void
    {
        // generate() menyusun nama_berkas dari nilai "format" mentah
        // (laporan-resmi-...-sd-....{format}), yang lalu menjadi bagian PATH
        // penyimpanan di BuatLaporanResmiJob — nilai bebas di sini berarti
        // jalur traversal pada berkas yang ditulis. Ditolak di FormRequest,
        // sebelum sempat menyentuh baris riwayat maupun job sama sekali.
        Storage::fake('local');

        ['upt' => $upt] = $this->hirarki();
        Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/admin/laporan/generate', [
                'dari' => '2026-09-01',
                'sampai' => '2026-09-30',
                'format' => '../../../../etc/passwd',
            ])
            ->assertSessionHasErrors('format');

        $this->assertSame(0, RiwayatLaporan::query()->count());
    }

    #[Test]
    public function generate_pdf_mengantre_lalu_selesai_dengan_berkas_pdf_potret(): void
    {
        Storage::fake('local');

        ['upt' => $upt] = $this->hirarki();
        Pegawai::factory()->create(['nama' => 'Ahmad Fauzi', 'unit_kerja_id' => $upt->id]);
        $this->eventPada('2026-09-05', $upt);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/admin/laporan/generate', ['dari' => '2026-09-01', 'sampai' => '2026-09-30', 'format' => 'pdf'])
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $riwayat = RiwayatLaporan::query()->sole();
        $this->assertSame('selesai', $riwayat->status->value);
        $this->assertSame('laporan-resmi-20260901-sd-20260930.pdf', $riwayat->nama_berkas);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($riwayat->path));
    }

    #[Test]
    public function generate_word_mengantre_lalu_selesai_dengan_docx_sungguhan(): void
    {
        Storage::fake('local');

        ['upt' => $upt] = $this->hirarki();
        Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/admin/laporan/generate', ['dari' => '2026-09-01', 'sampai' => '2026-09-30', 'format' => 'docx'])
            ->assertRedirect();

        $riwayat = RiwayatLaporan::query()->sole();
        $this->assertSame('selesai', $riwayat->status->value);
        $this->assertStringStartsWith("PK\x03\x04", Storage::disk('local')->get($riwayat->path));
    }

    #[Test]
    public function generate_excel_mengantre_lalu_selesai_dengan_xlsx_sungguhan(): void
    {
        Storage::fake('local');

        ['upt' => $upt] = $this->hirarki();
        Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/admin/laporan/generate', ['dari' => '2026-09-01', 'sampai' => '2026-09-30', 'format' => 'xlsx'])
            ->assertRedirect();

        $riwayat = RiwayatLaporan::query()->sole();
        $this->assertSame('selesai', $riwayat->status->value);
        $this->assertStringStartsWith("PK\x03\x04", Storage::disk('local')->get($riwayat->path));
    }

    #[Test]
    public function generate_mengikuti_cakupan_peran_yang_sama_dengan_unduh_data(): void
    {
        Storage::fake('local');

        // Admin UPT hanya boleh melihat unitnya sendiri — jaminan yang sama
        // dengan "Unduh Data", sebab generate() memakai LaporanResmiService
        // yang menghormati cakupan peran yang sama.
        ['upt' => $upt, 'lain' => $lain] = $this->hirarki();
        Pegawai::factory()->create(['nama' => 'Punya UPT', 'unit_kerja_id' => $upt->id]);
        Pegawai::factory()->create(['nama' => 'Punya Unit Lain', 'unit_kerja_id' => $lain->id]);
        $this->eventPada('2026-09-05', $upt);
        $this->eventPada('2026-09-05', $lain);

        $adminUpt = User::factory()->create(['role' => PeranPengguna::AdminUpt, 'unit_kerja_id' => $upt->id]);

        $this->actingAs($adminUpt)
            ->post('/admin/laporan/generate', ['dari' => '2026-09-01', 'sampai' => '2026-09-30', 'format' => 'xlsx'])
            ->assertRedirect();

        $riwayat = RiwayatLaporan::query()->sole();
        $this->assertSame('selesai', $riwayat->status->value);

        $sementara = tempnam(sys_get_temp_dir(), 'laporan-resmi-uji').'.xlsx';
        file_put_contents($sementara, Storage::disk('local')->get($riwayat->path));

        $sheet = IOFactory::load($sementara)->getActiveSheet();
        $isiSheet = '';

        for ($baris = 1; $baris <= $sheet->getHighestRow(); $baris++) {
            $isiSheet .= ' '.$sheet->getCell("A{$baris}")->getValue();
        }

        unlink($sementara);

        $this->assertStringContainsString($upt->nama, $isiSheet);
        $this->assertStringNotContainsString($lain->nama, $isiSheet);
    }

    #[Test]
    public function generate_menyimpan_pesan_galat_generik_ketika_perakitan_gagal(): void
    {
        // Kesalahan internal tidak boleh bocor ke pengguna sebagai pesan
        // mentah — lihat BuatLaporanResmiJob::handle(). Dipaksa gagal lewat
        // mock LaporanResmiService, sebab lewat masukan biasa susun() selalu
        // berhasil (unit_kerja_id tak dikenal sekalipun sudah ditolak lebih
        // dulu oleh validasi FilterLaporanRequest).
        Storage::fake('local');

        ['upt' => $upt] = $this->hirarki();
        Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);

        $this->mock(LaporanResmiService::class, function ($mock) {
            $mock->shouldReceive('susun')->andThrow(new \RuntimeException('kegagalan internal seharusnya tidak terlihat pengguna'));
        });

        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/admin/laporan/generate', ['dari' => '2026-09-01', 'sampai' => '2026-09-30', 'format' => 'pdf'])
            ->assertRedirect();

        $riwayat = RiwayatLaporan::query()->sole();
        $this->assertSame('gagal', $riwayat->status->value);
        $this->assertNotNull($riwayat->pesan_galat);
        $this->assertStringNotContainsString('kegagalan internal', $riwayat->pesan_galat);
    }

    #[Test]
    public function preview_menampilkan_pdf_inline_tanpa_membuat_riwayat(): void
    {
        ['upt' => $upt] = $this->hirarki();
        Pegawai::factory()->create(['unit_kerja_id' => $upt->id]);
        $this->eventPada('2026-09-05', $upt);

        $jawaban = $this->actingAs(User::factory()->superadmin()->create())
            ->get('/admin/laporan/preview?dari=2026-09-01&sampai=2026-09-30')
            ->assertOk();

        $this->assertStringContainsString('inline', $jawaban->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $jawaban->getContent());
        $this->assertSame(0, RiwayatLaporan::query()->count());
    }

    /**
     * `Excel::download()` mengembalikan BinaryFileResponse — Symfony sengaja
     * membuat `getContent()`-nya selalu kosong (isinya distream langsung dari
     * berkas sementara, tidak pernah disimpan di memori), berbeda dari CSV
     * dan PDF yang lewat StreamedResponse biasa. Baca langsung dari berkas
     * sementaranya, bukan dari respons.
     */
    protected function isiBerkasUnduhan(TestResponse $jawaban): string
    {
        $base = $jawaban->baseResponse;

        return $base instanceof BinaryFileResponse
            ? file_get_contents($base->getFile()->getPathname())
            : $jawaban->streamedContent();
    }
}
