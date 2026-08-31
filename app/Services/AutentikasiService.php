<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Autentikasi akun admin (FR-AUTH-01).
 */
class AutentikasiService
{
    /**
     * Jumlah percobaan gagal sebelum login dikunci sementara.
     */
    public const int BATAS_PERCOBAAN = 5;

    /**
     * Lama penguncian dalam detik.
     */
    public const int DURASI_KUNCI = 60;

    /**
     * @param  array{email: string, password: string, ingat_saya?: bool}  $kredensial
     *
     * @throws ValidationException
     */
    public function masuk(Request $request, array $kredensial): User
    {
        $kunci = $this->kunciPembatas($request, $kredensial['email']);

        $this->pastikanBelumDikunci($kunci);

        $berhasil = Auth::attempt(
            ['email' => $kredensial['email'], 'password' => $kredensial['password'], 'aktif' => true],
            $kredensial['ingat_saya'] ?? false,
        );

        if (! $berhasil) {
            RateLimiter::hit($kunci, self::DURASI_KUNCI);

            throw ValidationException::withMessages([
                'email' => 'Alamat surel atau kata sandi tidak sesuai, atau akun Anda tidak aktif.',
            ]);
        }

        RateLimiter::clear($kunci);
        $request->session()->regenerate();

        return $request->user();
    }

    public function keluar(Request $request): void
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * @throws ValidationException
     */
    protected function pastikanBelumDikunci(string $kunci): void
    {
        if (! RateLimiter::tooManyAttempts($kunci, self::BATAS_PERCOBAAN)) {
            return;
        }

        $detik = RateLimiter::availableIn($kunci);

        throw ValidationException::withMessages([
            'email' => "Terlalu banyak percobaan masuk. Silakan coba lagi dalam {$detik} detik.",
        ]);
    }

    protected function kunciPembatas(Request $request, string $email): string
    {
        return Str::transliterate(Str::lower($email).'|'.$request->ip());
    }
}
