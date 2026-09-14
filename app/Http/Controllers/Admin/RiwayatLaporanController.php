<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RiwayatLaporan;
use App\Services\Laporan\RiwayatLaporanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Riwayat Generate Laporan Resmi (FR-LAP-04, revisi antrian) — melihat
 * status, mengunduh berkas yang sudah selesai, dan menghapus baris riwayat.
 *
 * Cakupannya BUKAN cakupan unit kerja biasa: {@see RiwayatLaporanService}
 * menentukan kepemilikan dari SIAPA YANG MEMINTA laporannya, dan hanya
 * superadmin yang boleh melihat/menghapus milik orang lain.
 */
class RiwayatLaporanController extends Controller
{
    public function __construct(protected RiwayatLaporanService $riwayat) {}

    /**
     * Dipanggil berkala dari layar Laporan selama ada baris yang masih
     * antre/diproses — bukan kunjungan Inertia biasa, supaya tabel di
     * belakangnya (dan posisi gulir) tidak ikut tersegarkan.
     */
    public function data(Request $request): JsonResponse
    {
        return response()->json(['riwayat' => $this->riwayat->untukLayar($request->user())]);
    }

    public function unduh(Request $request, RiwayatLaporan $riwayatLaporan): StreamedResponse
    {
        abort_unless($this->riwayat->bolehMelihat($request->user(), $riwayatLaporan), 403);
        abort_unless($riwayatLaporan->selesai() && $riwayatLaporan->path !== null, 404);
        abort_unless(Storage::disk('local')->exists($riwayatLaporan->path), 404);

        return Storage::disk('local')->download($riwayatLaporan->path, $riwayatLaporan->nama_berkas);
    }

    public function hapus(Request $request, RiwayatLaporan $riwayatLaporan): RedirectResponse
    {
        abort_unless($this->riwayat->bolehMelihat($request->user(), $riwayatLaporan), 403);

        $this->riwayat->hapus($riwayatLaporan);

        return back()->with('sukses', 'Riwayat laporan dihapus.');
    }
}
