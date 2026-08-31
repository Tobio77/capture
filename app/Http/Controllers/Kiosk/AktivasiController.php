<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\AktivasiKioskRequest;
use App\Services\KioskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

class AktivasiController extends Controller
{
    public function __construct(protected KioskService $kiosk) {}

    /**
     * Layar aktivasi perangkat (UIUX §4.1).
     */
    public function create(Request $request): Response|RedirectResponse
    {
        if ($this->kiosk->kioskDariToken($request->cookie(KioskService::NAMA_COOKIE))) {
            return redirect()->route('kiosk.utama');
        }

        return Inertia::render('Kiosk/Aktivasi');
    }

    /**
     * Tukarkan kode aktivasi dengan device_token perangkat.
     */
    public function store(AktivasiKioskRequest $request): RedirectResponse
    {
        ['token' => $token] = $this->kiosk->aktifkan(
            $request->string('kode_aktivasi')->toString(),
            $request,
        );

        return redirect()
            ->route('kiosk.utama')
            ->with('sukses', 'Perangkat berhasil diaktifkan.')
            ->withCookie(Cookie::make(
                name: KioskService::NAMA_COOKIE,
                value: $token,
                minutes: KioskService::MASA_COOKIE_MENIT,
                httpOnly: true,
                sameSite: 'lax',
            ));
    }

    /**
     * Lepaskan perangkat dari titik absen ini dan cabut token-nya.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->kiosk->lepas($request->kiosk());

        return redirect()
            ->route('kiosk.aktivasi')
            ->with('sukses', 'Perangkat telah dilepaskan. Masukkan kode aktivasi baru untuk menggunakannya kembali.')
            ->withCookie(Cookie::forget(KioskService::NAMA_COOKIE));
    }
}
