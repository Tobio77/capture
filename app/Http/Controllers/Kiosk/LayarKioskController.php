<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LayarKioskController extends Controller
{
    /**
     * Layar utama kiosk. Dua panel Capture Foto & Daftar e-Presensi
     * dibangun pada Sesi S13.
     */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Kiosk/Utama');
    }
}
