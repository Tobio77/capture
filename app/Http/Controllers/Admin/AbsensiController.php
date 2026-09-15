<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Services\AbsensiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Hapus satu baris absensi — superadmin saja (otorisasi lewat middleware
 * `peran:superadmin` pada route, bukan diperiksa ulang di sini).
 *
 * Dipakai untuk keperluan pengujian atau membetulkan tap yang keliru
 * tercatat, BUKAN jalur normal. Setiap penghapusan tetap tercatat pada
 * audit trail lewat {@see AbsensiService::hapus()} — baris hilang dari
 * rekap, tetapi jejaknya tidak.
 */
class AbsensiController extends Controller
{
    public function __construct(protected AbsensiService $absensi) {}

    public function destroy(Request $request, Absensi $absensi): RedirectResponse
    {
        $this->absensi->hapus($absensi, $request->user());

        return back()->with('sukses', 'Baris absensi dihapus.');
    }
}
