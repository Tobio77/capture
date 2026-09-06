<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MasukRequest;
use App\Services\AutentikasiService;
use App\Services\CaptchaHitungService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SesiController extends Controller
{
    public function __construct(
        protected AutentikasiService $autentikasi,
        protected CaptchaHitungService $captcha,
    ) {}

    /**
     * Tampilkan formulir masuk admin.
     *
     * CAPTCHA-nya progresif (FR-AUTH-03): ia tidak pernah muncul pada
     * percobaan pertama, dan soalnya sudah tersedia SEBELUM tombol ditekan —
     * bukan muncul sesudah satu kegagalan tambahan yang tidak dimengerti
     * sebabnya oleh orang yang sedang buru-buru.
     */
    public function create(Request $request): Response
    {
        $perlu = $this->autentikasi->perluCaptchaUntuk($request);

        return Inertia::render('Auth/Masuk', [
            'perlu_captcha' => $perlu,
            'soal_captcha' => $perlu ? $this->captcha->soal($request) : null,
        ]);
    }

    /**
     * Proses permintaan masuk (FR-AUTH-01).
     */
    public function store(MasukRequest $request): RedirectResponse
    {
        $this->autentikasi->masuk($request, $request->validated());

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Akhiri sesi admin.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->autentikasi->keluar($request);

        return redirect()->route('masuk')->with('sukses', 'Anda telah keluar dari sistem.');
    }
}
