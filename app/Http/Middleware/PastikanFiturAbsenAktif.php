<?php

namespace App\Http\Middleware;

use App\Services\SettingAbsenService;
use App\Services\TitikAbsenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menutup seluruh jalur sebuah fitur absen yang dinonaktifkan admin.
 *
 * Contoh pemakaian: ->middleware('fitur.absen:umum') atau 'fitur.absen:event'.
 *
 * Sakelarnya ada di Setting Absen → Fitur Absensi. Selama sebuah fitur mati,
 * layarnya tidak dapat dibuka dan endpoint-nya — tap, simpan, daftar
 * presensi, foto — menolak setiap permintaan. Pemeriksaannya dipusatkan di
 * sini, bukan disebar ke tiap controller, supaya tidak ada satu endpoint pun
 * yang lupa ikut tertutup.
 *
 * Yang TIDAK ikut tertutup: pemantauan, Rekap, dan Laporan di panel admin.
 * Data yang sudah tercatat tetap harus dapat dibaca, dan sesi Absen Umum hari
 * itu tidak dihapus — menyalakan kembali fiturnya melanjutkan sesi yang sama.
 */
class PastikanFiturAbsenAktif
{
    public function __construct(protected SettingAbsenService $setting) {}

    public function handle(Request $request, Closure $next, string $fitur): Response
    {
        $umum = $fitur === TitikAbsenService::MODE_UMUM;
        $setting = $this->setting->ambil();

        if ($setting[$umum ? 'absen_umum_aktif' : 'absen_event_aktif']) {
            return $next($request);
        }

        $pesan = $umum
            ? 'Fitur Absen Umum sedang dinonaktifkan oleh admin.'
            : 'Fitur Absen Event sedang dinonaktifkan oleh admin.';

        /*
         * Layar dipulangkan ke tempat asalnya dengan keterangan, bukan
         * disuguhi halaman galat: perangkat ke beranda, admin ke halaman
         * pemantauan Absen Umum.
         */
        if ($request->routeIs('*.layar')) {
            return redirect()
                ->route($request->kiosk() === null ? 'absen-umum.index' : 'beranda')
                ->with('gagal', $pesan);
        }

        // Bentuk penolakannya sama dengan penolakan tap lain, sehingga layar
        // absen menampilkannya tanpa perlu cabang khusus.
        return response()->json([
            'success' => false,
            'code' => 'FITUR_NONAKTIF',
            'message' => $pesan,
        ], 403);
    }
}
