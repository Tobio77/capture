<?php

namespace App\Http\Controllers\Absen;

use App\Http\Controllers\Controller;
use App\Services\AbsensiService;
use App\Services\TitikAbsenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Daftar e-Presensi terkini untuk layar kiosk (FR-TAP-08).
 *
 * Ditarik berkala oleh kiosk supaya tabel ikut bertambah ketika pegawai lain
 * men-tap di kiosk lain pada event yang sama. Jawaban juga membawa keadaan
 * event, sehingga kiosk mengetahui entry yang ditutup admin tanpa perlu
 * dimuat ulang (FR-EVT-04).
 */
class DaftarPresensiController extends Controller
{
    public function __construct(
        protected AbsensiService $absensi,
        protected TitikAbsenService $titik,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /*
         * Polling saja tidak membuka sesi absen umum: perangkat yang menyala
         * sepanjang hari libur tidak boleh meninggalkan sesi kosong yang
         * kemudian terhitung sebagai hari yang wajib dihadiri pada laporan.
         */
        ['event' => $event] = $this->titik->untuk($request);

        if ($event === null) {
            return response()->json(['event' => null, 'daftar_presensi' => []]);
        }

        return response()->json([
            'event' => [
                'id' => $event->id,
                'nama' => $event->nama,
                'tanggal' => $event->tanggal->toDateString(),
                'jam_mulai' => substr((string) $event->jam_mulai, 0, 5),
                'toleransi_menit' => $event->toleransi_menit,
            ],
            'daftar_presensi' => $this->absensi->daftarPresensi(
                $event,
                fn (int $id) => $this->titik->urlFotoAbsen($request, $id),
            ),
        ]);
    }
}
