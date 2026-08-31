<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menghentikan sesi pengguna yang akunnya dinonaktifkan setelah ia login
 * (FR-USR-01: penonaktifan akun harus berlaku seketika).
 */
class PastikanPenggunaAktif
{
    public function handle(Request $request, Closure $next): Response
    {
        $pengguna = $request->user();

        if ($pengguna && ! $pengguna->aktif) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('masuk')
                ->with('gagal', 'Akun Anda telah dinonaktifkan. Hubungi Superadmin.');
        }

        return $next($request);
    }
}
