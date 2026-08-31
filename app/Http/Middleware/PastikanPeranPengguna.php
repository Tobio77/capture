<?php

namespace App\Http\Middleware;

use App\Enums\PeranPengguna;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membatasi akses route berdasarkan peran pengguna (FR-AUTH-02).
 *
 * Contoh pemakaian: ->middleware('peran:superadmin,admin_dinas')
 */
class PastikanPeranPengguna
{
    public function handle(Request $request, Closure $next, string ...$peran): Response
    {
        $pengguna = $request->user();

        if (! $pengguna) {
            abort(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        $diizinkan = array_map(
            fn (string $nilai) => PeranPengguna::from($nilai),
            $peran,
        );

        if (! $pengguna->berperan(...$diizinkan)) {
            abort(403, 'Peran '.$pengguna->role->label().' tidak memiliki akses ke halaman ini.');
        }

        return $next($request);
    }
}
