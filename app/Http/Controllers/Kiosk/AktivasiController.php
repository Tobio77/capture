<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\AktivasiKioskRequest;
use App\Models\UnitKerja;
use App\Services\KioskService;
use App\Services\SettingAbsenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

class AktivasiController extends Controller
{
    public function __construct(
        protected KioskService $kiosk,
        protected SettingAbsenService $setting,
    ) {}

    /**
     * Layar aktivasi perangkat (UIUX §4.1).
     */
    public function create(Request $request): Response|RedirectResponse
    {
        if ($this->kiosk->kioskDariToken($request->cookie(KioskService::NAMA_COOKIE))) {
            return redirect()->route('kiosk.utama');
        }

        $modeTerbuka = $this->setting->modeTerbuka();

        return Inertia::render('Kiosk/Aktivasi', [
            'mode_terbuka' => $modeTerbuka,

            /*
             * Unit kerja hanya dikirim ketika Mode Terbuka menyala. Pada mode
             * biasa, layar aktivasi tidak perlu — dan tidak boleh — membocorkan
             * daftar unit kerja kepada mesin yang belum punya kode apa pun.
             */
            'unit_kerja' => $modeTerbuka
                ? UnitKerja::query()
                    ->levelTeratas()
                    ->aktif()
                    ->orderBy('nama')
                    ->get(['id', 'kode', 'nama'])
                : [],
        ]);
    }

    /**
     * Masuk tanpa kode aktivasi selagi Mode Terbuka menyala (FR-SET-06).
     *
     * Pemeriksaan settingnya diulang di sini, bukan hanya di layar: layar
     * hanyalah tampilan, dan permintaan ini dapat dikirim langsung oleh siapa
     * pun yang tahu alamatnya.
     */
    public function terbuka(Request $request): RedirectResponse
    {
        abort_unless(
            $this->setting->modeTerbuka(),
            403,
            'Perangkat wajib diaktifkan dengan kode. Mintakan kode aktivasi kepada admin.',
        );

        $data = $request->validate([
            'unit_kerja_id' => ['required', 'integer', 'exists:unit_kerja,id'],
        ]);

        $unitKerja = UnitKerja::query()->findOrFail($data['unit_kerja_id']);

        ['token' => $token] = $this->kiosk->masukTanpaKode($unitKerja, $request);

        return redirect()
            ->route('kiosk.utama')
            ->with('sukses', 'Perangkat masuk tanpa kode aktivasi (Mode Terbuka).')
            ->withCookie(Cookie::make(
                name: KioskService::NAMA_COOKIE,
                value: $token,
                minutes: KioskService::MASA_COOKIE_MENIT,
                httpOnly: true,
                sameSite: 'lax',
            ));
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
