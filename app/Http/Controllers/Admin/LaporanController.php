<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\FilterLaporanRequest;
use App\Services\EksporService;
use App\Services\LaporanService;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laporan kehadiran per pegawai (FR-LAP-01 s.d. FR-LAP-03).
 */
class LaporanController extends Controller
{
    public function __construct(
        protected LaporanService $laporan,
        protected EksporService $ekspor,
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

        if ($request->string('format')->toString() === 'pdf') {
            return $this->ekspor->unduhPdf('cetak.laporan', [
                'baris' => $baris,
                'ringkasan' => $this->laporan->ringkasanUntuk($baris),
                'jumlah_event' => $hasil['jumlah_event'],
                'dari' => $dari->translatedFormat('d F Y'),
                'sampai' => $sampai->translatedFormat('d F Y'),
                'cakupan' => $this->namaCakupan($request),
            ], "{$nama}.pdf");
        }

        return $this->ekspor->unduhCsv(
            $this->ekspor->csv(
                ['NIP', 'Nama', 'Unit Kerja', 'Event Berlaku', 'Hadir', 'Terlambat', 'Tanpa Keterangan'],
                $baris->map(fn (array $isi) => [
                    $isi['nip'],
                    $isi['nama'],
                    $isi['unit_kerja'] ?? '',
                    $isi['event_berlaku'],
                    $isi['hadir'],
                    $isi['terlambat'],
                    $isi['tanpa_keterangan'],
                ]),
            ),
            "{$nama}.csv",
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
