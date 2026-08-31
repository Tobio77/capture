<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MasukRequest;
use App\Services\AutentikasiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SesiController extends Controller
{
    public function __construct(protected AutentikasiService $autentikasi) {}

    /**
     * Tampilkan formulir masuk admin.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Masuk');
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
