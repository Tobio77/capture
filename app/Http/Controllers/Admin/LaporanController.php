<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\FilterLaporanRequest;
use App\Services\LaporanService;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan kehadiran per pegawai (FR-LAP-01 s.d. FR-LAP-03).
 */
class LaporanController extends Controller
{
    public function __construct(protected LaporanService $laporan) {}

    public function index(FilterLaporanRequest $request): Response
    {
        [$dari, $sampai, $unitKerjaId] = $request->rentang();

        $hasil = $this->laporan->rekap($request->user(), $dari, $sampai, $unitKerjaId);

        return Inertia::render('Laporan/Index', [
            'baris' => $hasil['baris'],
            'ringkasan' => $hasil['ringkasan'],
            'jumlah_event' => $hasil['jumlah_event'],
            'unit_kerja' => $this->laporan->unitKerjaTersedia($request->user()),
            'filter' => [
                'dari' => $dari->toDateString(),
                'sampai' => $sampai->toDateString(),
                'unit_kerja_id' => $unitKerjaId,
            ],
        ]);
    }

    /**
     * Unduh laporan sebagai CSV yang dapat dibuka Excel (FR-LAP-03).
     */
    public function ekspor(FilterLaporanRequest $request): StreamedResponse
    {
        [$dari, $sampai, $unitKerjaId] = $request->rentang();

        $hasil = $this->laporan->rekap($request->user(), $dari, $sampai, $unitKerjaId);
        $isi = $this->laporan->csv($hasil['baris']);

        $namaBerkas = sprintf(
            'laporan-kehadiran-%s-sd-%s.csv',
            $dari->format('Ymd'),
            $sampai->format('Ymd'),
        );

        return response()->streamDownload(
            fn () => print ($isi),
            $namaBerkas,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
