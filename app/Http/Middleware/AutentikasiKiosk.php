<?php

namespace App\Http\Middleware;

use App\Services\KioskService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi perangkat kiosk melalui device_token pada cookie terenkripsi (NFR-03).
 * Token yang dicabut atau kiosk yang dinonaktifkan langsung kehilangan akses.
 */
class AutentikasiKiosk
{
    public function __construct(protected KioskService $kiosk) {}

    public function handle(Request $request, Closure $next): Response
    {
        $perangkat = $this->kiosk->kioskDariToken($request->cookie(KioskService::NAMA_COOKIE));

        if (! $perangkat) {
            return redirect()
                ->route('kiosk.aktivasi')
                ->with('gagal', 'Perangkat ini belum diaktifkan atau aksesnya telah dicabut. Masukkan kode aktivasi dari admin.');
        }

        $this->kiosk->perbaruiJejak($perangkat, $request);

        $request->attributes->set('kiosk', $perangkat);

        return $next($request);
    }
}
