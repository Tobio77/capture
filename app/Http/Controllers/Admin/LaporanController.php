<?php

namespace App\Http\Controllers\Admin;

use App\Exports\TabelDataExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\FilterLaporanRequest;
use App\Jobs\BuatLaporanResmiJob;
use App\Services\EksporService;
use App\Services\Laporan\LaporanResmiService;
use App\Services\Laporan\RiwayatLaporanService;
use App\Services\LaporanService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laporan kehadiran per pegawai (FR-LAP-01 s.d. FR-LAP-03) dan Laporan Resmi
 * (FR-LAP-04).
 *
 * Dua konsep yang berbeda tujuannya, dan sengaja dijaga tetap berbeda di
 * kode: `ekspor()` (dan `index()`) adalah "Unduh Data" — tabel mentah, cepat,
 * tanpa narasi — sementara `generate()`/`preview()` adalah "Generate
 * Laporan": dokumen kop surat siap cetak dengan kesimpulan dan rekomendasi
 * bertemplat. Label tombolnya di layar pun sengaja dibedakan, bukan varian
 * dari tombol yang sama.
 *
 * `generate()` TIDAK LAGI langsung mengembalikan berkas (revisi antrian):
 * ia membuat satu baris Riwayat Laporan dan mengantrekan pembuatannya lewat
 * {@see BuatLaporanResmiJob}, lalu kembali seketika. Berkasnya
 * diunduh belakangan dari Riwayat Laporan, lewat
 * {@see RiwayatLaporanController}.
 */
class LaporanController extends Controller
{
    /**
     * Kolom yang dapat dipilih untuk "Unduh Data" (CSV/Excel), berurutan
     * tetap. NIP dan Nama selalu ikut apa pun yang diminta klien — tabel
     * tanpa satu pun cara mengenali barisnya tidak ada gunanya. Bukan bagian
     * dari Generate Laporan, yang kolomnya tetap sebab bentuknya dokumen
     * resmi, bukan tabel untuk diolah lanjut.
     *
     * @var array<string, string>
     */
    protected const array KOLOM = [
        'nip' => 'NIP',
        'nama' => 'Nama',
        'unit_kerja' => 'Unit Kerja',
        'tanggal_label' => 'Tanggal',
        'kegiatan' => 'Kegiatan',
        'jam_masuk' => 'Jam Masuk',
        'jam_pulang' => 'Jam Pulang',
        'status_label' => 'Status',
        'metode' => 'Metode',
        'perangkat' => 'Perangkat',
        'ip_address' => 'Alamat IP',
    ];

    /**
     * Kolom yang SELALU ikut, apa pun yang dicentang di checklist.
     *
     * NIP dan Nama karena tabel tanpa satu pun cara mengenali barisnya tidak
     * ada gunanya; Tanggal karena satu pegawai punya banyak baris di sini, dan
     * baris tanpa tanggal tidak dapat dibedakan satu sama lain; Jam Masuk dan
     * Jam Pulang karena justru itulah yang dicari pembaca laporan kehadiran.
     *
     * @var array<int, string>
     */
    protected const array KOLOM_WAJIB = [
        'nip', 'nama', 'tanggal_label', 'jam_masuk', 'jam_pulang',
    ];

    public function __construct(
        protected LaporanService $laporan,
        protected EksporService $ekspor,
        protected LaporanResmiService $laporanResmi,
        protected RiwayatLaporanService $riwayat,
    ) {}

    public function index(FilterLaporanRequest $request): InertiaResponse
    {
        [$dari, $sampai, $unitKerjaId] = $request->rentang();
        $cari = $request->string('cari')->toString();

        $hasil = $this->laporan->halaman(
            $request->user(),
            $dari,
            $sampai,
            $unitKerjaId,
            $cari,
            max(1, (int) $request->integer('page', 1)),
        );

        return Inertia::render('Laporan/Index', [
            'baris' => $hasil['baris'],
            'ringkasan' => $hasil['ringkasan'],
            'jumlah_event' => $hasil['jumlah_event'],
            'unit_kerja' => $this->laporan->unitKerjaTersedia($request->user()),
            'filter' => [
                'dari' => $dari->toDateString(),
                'sampai' => $sampai->toDateString(),
                'unit_kerja_id' => $unitKerjaId ?? '',
                'cari' => $cari,
            ],
            'riwayat' => $this->riwayat->untukLayar($request->user()),
        ]);
    }

    /**
     * Unduh laporan sebagai CSV atau PDF (FR-LAP-03).
     *
     * Berkas selalu memuat seluruh baris hasil penyaringan, bukan halaman yang
     * kebetulan sedang dibuka — lampiran administratif yang terpotong halaman
     * tidak ada gunanya.
     */
    public function ekspor(FilterLaporanRequest $request): Response
    {
        [$dari, $sampai, $unitKerjaId] = $request->rentang();

        $hasil = $this->laporan->rekap($request->user(), $dari, $sampai, $unitKerjaId);
        $baris = $this->laporan->saring($hasil['baris'], $request->string('cari')->toString());

        $nama = sprintf('laporan-kehadiran-%s-sd-%s', $dari->format('Ymd'), $sampai->format('Ymd'));
        $format = $request->string('format')->toString();

        if ($format === 'pdf') {
            return $this->ekspor->unduhPdf('cetak.laporan', [
                'baris' => $baris,
                'ringkasan' => $this->laporan->ringkasanUntuk($baris),
                'jumlah_event' => $hasil['jumlah_event'],
                'dari' => $dari->translatedFormat('d F Y'),
                'sampai' => $sampai->translatedFormat('d F Y'),
                'cakupan' => $this->namaCakupan($request),
            ], "{$nama}.pdf");
        }

        /*
         * Unduhan tabel (CSV/Excel) memuat RINCIAN, bukan agregat yang tampil
         * di layar: satu baris per pegawai per sesi absen, lengkap dengan jam
         * masuk dan jam pulang yang tercatat.
         *
         * Keduanya sengaja berbeda grain. Layar menjawab "berapa kali hadir,
         * berapa kali terlambat" — bentuk yang tepat untuk dibaca sekilas.
         * Berkas yang diunduh justru diolah lanjut dan dilampirkan, dan
         * pertanyaan yang dibawa ke sana adalah "pukul berapa orang ini datang
         * dan pulang" — yang tidak pernah dapat dijawab oleh hitungan. Lembar
         * PDF di atas tetap memuat agregatnya, sebab ia dibaca, bukan diolah.
         */
        $rincian = $this->laporan->saring(
            $this->laporan->rincian($request->user(), $dari, $sampai, $unitKerjaId),
            $request->string('cari')->toString(),
        );

        $kolomAktif = $this->ekspor->kolomAktif($request, self::KOLOM, self::KOLOM_WAJIB);
        $judul = array_map(fn (string $kunci) => self::KOLOM[$kunci], $kolomAktif);
        $baris = $rincian->map(fn (array $isi) => array_map(fn (string $kunci) => $isi[$kunci] ?? '', $kolomAktif));

        if ($format === 'xlsx') {
            return Excel::download(new TabelDataExport($judul, $baris->all()), "{$nama}.xlsx");
        }

        return $this->ekspor->unduhCsv($this->ekspor->csv($judul, $baris), "{$nama}.csv");
    }

    /**
     * Antrekan pembuatan Laporan Resmi: dokumen kop surat dengan kesimpulan
     * dan rekomendasi bertemplat, tersedia PDF/Word/Excel (FR-LAP-04).
     *
     * Memakai filter aktif yang SAMA dengan "Unduh Data" ({@see
     * FilterLaporanRequest::rentang()}) — periode dan unit yang sedang
     * dilihat di layar itulah yang tercetak, tanpa formulir filter kedua.
     * Pencarian nama/NIP (`cari`) sengaja TIDAK ikut: dokumen resmi ini
     * berisi ringkasan per unit, bukan daftar pegawai yang dapat disaring.
     *
     * TIDAK langsung mengembalikan berkas (revisi antrian, lihat docblock
     * kelas ini) — berkasnya dirakit BuatLaporanResmiJob sesaat setelah
     * jawaban ini terkirim, dan diunduh belakangan dari Riwayat Laporan.
     */
    public function generate(FilterLaporanRequest $request): RedirectResponse
    {
        [$dari, $sampai, $unitKerjaId] = $request->rentang();
        $format = $request->string('format')->toString();
        $nama = sprintf('laporan-resmi-%s-sd-%s.%s', $dari->format('Ymd'), $sampai->format('Ymd'), $format);

        $this->riwayat->buat($request->user(), $format, $dari, $sampai, $unitKerjaId, $nama);

        return back()->with('sukses', 'Laporan sedang diproses. Lihat progresnya di Riwayat Laporan di bawah.');
    }

    /**
     * Pratinjau PDF Laporan Resmi — ditampilkan langsung di tab peramban,
     * BUKAN diunduh maupun diantrekan lewat Riwayat Laporan. Selalu PDF apa
     * pun format yang akan dipilih admin nantinya: satu-satunya format yang
     * bisa ditampilkan langsung di peramban tanpa aplikasi tambahan, dan
     * isinya (ringkasan, kesimpulan, rekomendasi) sama persis di ketiga
     * format — yang beda hanya cara menuliskannya.
     */
    public function preview(FilterLaporanRequest $request): Response
    {
        [$dari, $sampai, $unitKerjaId] = $request->rentang();

        $data = $this->laporanResmi->susun($request->user(), $dari, $sampai, $unitKerjaId);
        $nama = sprintf('pratinjau-laporan-resmi-%s-sd-%s.pdf', $dari->format('Ymd'), $sampai->format('Ymd'));

        return $this->ekspor->tampilkanPdf(
            'cetak.laporan-resmi',
            ['data' => $data, 'formatPersen' => fn (?float $n) => LaporanResmiService::formatPersen($n)],
            $nama,
            'portrait',
        );
    }

    protected function namaCakupan(FilterLaporanRequest $request): string
    {
        $pengguna = $request->user();

        return $pengguna->lintasUnit()
            ? 'Seluruh unit kerja'
            : ($pengguna->unitKerja?->nama ?? 'Tanpa unit kerja');
    }
}
