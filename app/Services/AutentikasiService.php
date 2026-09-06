<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Autentikasi akun admin (FR-AUTH-01, FR-AUTH-03).
 *
 * Pertahanan berlapis dua, dan urutannya disengaja.
 *
 * **Lapis pertama — pembatasan laju bertingkat.** Ini pertahanan yang
 * sesungguhnya. Jumlah gagal berturut-turut dihitung per (surel + alamat IP),
 * dan setiap kelipatan {@see self::BATAS_PERCOBAAN} mengunci lebih lama:
 * 1 menit, 5 menit, 15 menit, lalu 30 menit. Versi sebelumnya mengunci 60
 * detik dan tetap 60 detik selamanya — cukup untuk memperlambat percobaan
 * iseng, tidak cukup untuk yang membiarkan skripnya berjalan semalaman: 60
 * detik per lima percobaan masih menyisakan 7.200 percobaan per hari.
 *
 * **Lapis kedua — CAPTCHA sejak percobaan pertama.** Ia BUKAN pengganti lapis
 * pertama, melainkan penghalang tambahan bagi alat isian massal.
 *
 * Rancangan progresif sebelumnya — CAPTCHA baru muncul setelah beberapa
 * kegagalan — dibatalkan atas permintaan pemilik sistem, dan alasannya kuat:
 * bot yang mencoba satu kombinasi pada satu akun lalu berpindah sasaran tidak
 * pernah menyentuh ambang apa pun, sehingga penghalangnya justru tidak pernah
 * terpasang pada pola serangan yang paling umum. Biayanya satu soal hitungan
 * bagi admin setiap kali masuk, dan itu dinilai sepadan.
 *
 * Cakupannya HANYA layar masuk Panel Admin; layar tap perangkat absen tidak
 * pernah melewatinya. Lihat {@see CaptchaHitungService}.
 *
 * Hitungan kegagalannya bertahan {@see self::UMUR_HITUNGAN} dan dibersihkan
 * begitu satu login berhasil — sehingga salah ketik sekali pagi ini tidak
 * menghukum orang yang sama besok.
 */
class AutentikasiService
{
    /** Jumlah gagal berturut-turut sebelum login dikunci sementara. */
    public const int BATAS_PERCOBAAN = 5;

    /** Lama hitungan kegagalan bertahan, dalam detik. */
    public const int UMUR_HITUNGAN = 3600;

    /**
     * Tangga lama penguncian, dalam detik.
     *
     * Naik bertingkat, bukan tetap: penyerang yang bertahan membayar makin
     * mahal, sementara admin yang salah ketik dua-tiga kali nyaris tidak
     * merasakannya.
     */
    protected const array TANGGA_KUNCI = [60, 300, 900, 1800];

    public function __construct(protected CaptchaHitungService $captcha) {}

    /**
     * @param  array{email: string, password: string, ingat_saya?: bool, jawaban_captcha?: string|null}  $kredensial
     *
     * @throws ValidationException
     */
    public function masuk(Request $request, array $kredensial): User
    {
        $kunci = $this->kunciPembatas($request, $kredensial['email']);

        $this->pastikanBelumDikunci($kunci);

        /*
         * CAPTCHA diperiksa SEBELUM kata sandi, dan kegagalannya ikut dihitung.
         * Kalau ia diperiksa belakangan, penyerang cukup mengabaikan soalnya
         * dan tetap memperoleh jawaban "sandi benar/salah" dari pesan galat.
         *
         * Diminta sejak percobaan PERTAMA. Rancangan progresif sebelumnya —
         * baru muncul setelah beberapa kegagalan — menukar keamanan dengan
         * kenyamanan harian, dan pertukaran itu dibatalkan atas permintaan
         * pemilik sistem: bot yang mencoba satu kombinasi lalu berpindah
         * sasaran tidak pernah sampai ke ambang mana pun.
         */
        if (! $this->captcha->benar($request, $kredensial['jawaban_captcha'] ?? null)) {
            $this->catatGagal($kunci);

            throw ValidationException::withMessages([
                'jawaban_captcha' => 'Jawaban hitungan tidak sesuai. Soal sudah diganti, silakan coba lagi.',
            ]);
        }

        $berhasil = Auth::attempt(
            ['email' => $kredensial['email'], 'password' => $kredensial['password'], 'aktif' => true],
            $kredensial['ingat_saya'] ?? false,
        );

        if (! $berhasil) {
            $this->catatGagal($kunci);

            throw ValidationException::withMessages([
                'email' => 'Alamat surel atau kata sandi tidak sesuai, atau akun Anda tidak aktif.',
            ]);
        }

        $this->bersihkan($kunci);
        $this->captcha->hapus($request);
        $request->session()->regenerate();

        return $request->user();
    }

    public function keluar(Request $request): void
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    protected function gagalBerturut(string $kunci): int
    {
        return (int) Cache::get("gagal-masuk:{$kunci}", 0);
    }

    /**
     * Catat satu kegagalan, lalu kunci bila sudah menyentuh kelipatan batas.
     */
    protected function catatGagal(string $kunci): void
    {
        foreach ([$kunci, $this->kunciAlamatDari($kunci)] as $k) {
            $jumlah = $this->gagalBerturut($k) + 1;

            Cache::put("gagal-masuk:{$k}", $jumlah, self::UMUR_HITUNGAN);

            if ($jumlah % self::BATAS_PERCOBAAN !== 0) {
                continue;
            }

            $tingkat = min(
                intdiv($jumlah, self::BATAS_PERCOBAAN) - 1,
                count(self::TANGGA_KUNCI) - 1,
            );
            $durasi = self::TANGGA_KUNCI[$tingkat];

            Cache::put("kunci-masuk:{$k}", Carbon::now()->addSeconds($durasi), $durasi);
        }
    }

    protected function bersihkan(string $kunci): void
    {
        foreach ([$kunci, $this->kunciAlamatDari($kunci)] as $k) {
            Cache::forget("gagal-masuk:{$k}");
            Cache::forget("kunci-masuk:{$k}");
        }
    }

    /**
     * @throws ValidationException
     */
    protected function pastikanBelumDikunci(string $kunci): void
    {
        foreach ([$kunci, $this->kunciAlamatDari($kunci)] as $k) {
            $sampai = Cache::get("kunci-masuk:{$k}");

            if ($sampai === null) {
                continue;
            }

            $detik = max(1, Carbon::now()->diffInSeconds($sampai, false));

            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak percobaan masuk. Silakan coba lagi dalam '
                    .$this->tertulis((int) ceil($detik)).'.',
            ]);
        }
    }

    /**
     * "3 menit" lebih berguna daripada "180 detik" bagi orang yang menunggu.
     */
    protected function tertulis(int $detik): string
    {
        return $detik < 60 ? "{$detik} detik" : ceil($detik / 60).' menit';
    }

    /**
     * Kunci per (surel + alamat IP): menyerang satu akun dari banyak alamat,
     * atau banyak akun dari satu alamat, keduanya tetap terhitung.
     */
    protected function kunciPembatas(Request $request, string $email): string
    {
        return Str::transliterate(Str::lower($email).'|'.$request->ip());
    }

    /**
     * Kunci kedua: alamat IP saja.
     *
     * Tanpa ini, penyerang cukup mengganti surel setiap lima percobaan untuk
     * memperoleh jatah baru — dan daftar surel dinas mudah ditebak dari pola
     * namanya. Alamat IP-nya yang tidak berganti.
     */
    protected function kunciAlamatDari(string $kunci): string
    {
        return 'ip|'.Str::afterLast($kunci, '|');
    }
}
