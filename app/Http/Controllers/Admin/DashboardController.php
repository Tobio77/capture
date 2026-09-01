<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard ringkasan kehadiran (FR-DASH-01, FR-DASH-02).
 */
class DashboardController extends Controller
{
    public function __construct(protected DashboardService $dashboard) {}

    public function __invoke(Request $request): Response
    {
        $pengguna = $request->user();

        return Inertia::render('Dashboard', [
            'statistik' => $this->dashboard->statistik($pengguna),
            'tren' => $this->dashboard->tren($pengguna),
        ]);
    }
}
