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
     * Tampilkan formulir masuk admin (FR-AUTH-03).
     *
     * Soal dibuat BARU setiap kali formulirnya digambar, bukan dipakai ulang.
     * Halaman ini digambar lagi setiap kali login gagal, sehingga jawaban lama
     * tidak pernah dapat diputar ulang untuk percobaan berikutnya.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Masuk', [
            'soal_captcha' => $this->captcha->buat($request),
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
