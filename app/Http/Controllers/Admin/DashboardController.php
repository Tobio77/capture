<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard ringkasan kehadiran (FR-DASH-01, FR-DASH-02).
 */
class DashboardController extends Controller
{
    public function __construct(protected DashboardService $dashboard) {}

    public function index(Request $request): Response
    {
        $pengguna = $request->user();

        return Inertia::render('Dashboard', [
            'statistik' => $this->dashboard->statistik($pengguna),
            'tren' => $this->dashboard->tren($pengguna),
            'aktivitas' => $this->dashboard->aktivitasTerbaru($pengguna),
            'ketepatan' => $this->dashboard->ketepatanHariIni($pengguna),
            'kesiapan' => $this->dashboard->kesiapan($pengguna),
            'peringkat_unit' => $this->dashboard->peringkatUnit($pengguna),
            'event_berjalan' => $this->dashboard->eventBerjalan($pengguna),
        ]);
    }

    /**
     * Aktivitas absen terbaru untuk pembaruan berkala (FR-DASH-03).
     *
     * Dijawab sebagai JSON supaya feed dapat menyegarkan dirinya sendiri
     * tanpa memuat ulang seluruh halaman beserta grafiknya.
     */
    public function aktivitas(Request $request): JsonResponse
    {
        return response()->json([
            'aktivitas' => $this->dashboard->aktivitasTerbaru($request->user()),
        ]);
    }
}
