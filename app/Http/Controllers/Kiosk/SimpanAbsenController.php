<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanAbsenRequest;
use App\Services\AbsensiService;
use App\Services\EventAbsenService;
use App\Services\KartuRfidService;
use App\Services\SettingAbsenService;
use Illuminate\Http\JsonResponse;

/**
 * Penyimpanan hasil absen dari kiosk (FR-TAP-05 s.d. FR-TAP-07).
 *
 * Kiosk sudah memutuskan cocok/tidaknya wajah di sisi klien, tetapi keputusan
 * itu datang dari peramban yang dapat dimanipulasi. Karena itu seluruh syarat
 * diperiksa ulang di sini sebelum baris tersimpan (SDD §1.2): event masih
 * dibuka dan mencakup kiosk, pegawai dikenal dan aktif, dan skor kecocokan
 * benar-benar melampaui ambang Setting Absen.
 */
class SimpanAbsenController extends Controller
{
    public function __construct(
        protected AbsensiService $absensi,
        protected EventAbsenService $event,
        protected KartuRfidService $kartu,
        protected SettingAbsenService $setting,
    ) {}

    public function __invoke(SimpanAbsenRequest $request): JsonResponse
    {
        $kiosk = $request->kiosk();
        $event = $kiosk === null ? null : $this->event->eventAktifUntukKiosk($kiosk);

        // FR-EVT-04: entry yang sudah ditutup menolak tap baru.
        if ($event === null) {
            return $this->gagal('EVENT_TIDAK_AKTIF', 'Entry event sudah ditutup. Absen tidak dicatat.', 409);
        }

        $pegawai = $this->kartu->kenali($request->string('id_card')->toString());

        if ($pegawai === null) {
            return $this->gagal('ID_TIDAK_DIKENAL', 'Kartu atau NIP tidak terdaftar dalam sistem.', 404);
        }

        if (! $pegawai->aktif) {
            return $this->gagal('PEGAWAI_TIDAK_AKTIF', 'Pegawai tidak aktif.', 403);
        }

        $setting = $this->setting->ambil();
        $skor = $request->has('skor') ? (float) $request->input('skor') : null;

        /*
         * FR-TAP-06: kehadiran hanya dicatat bila verifikasi wajah berhasil.
         * Ambang dibaca ulang dari Setting Absen, bukan dari kiriman kiosk,
         * supaya kiosk tidak dapat menurunkan syaratnya sendiri.
         */
        if ($setting['metode_wajah_aktif']) {
            if ($skor === null) {
                return $this->gagal('WAJAH_BELUM_DIVERIFIKASI', 'Verifikasi wajah wajib sebelum absen dicatat.', 422);
            }

            if ($skor < $setting['ambang_kecocokan_wajah']) {
                return $this->gagal(
                    'WAJAH_TIDAK_COCOK',
                    "Skor kecocokan {$skor}% di bawah ambang {$setting['ambang_kecocokan_wajah']}%.",
                    422,
                );
            }
        }

        $absensi = $this->absensi->catat($event, $pegawai, $kiosk, [
            'jenis' => $request->string('jenis')->toString(),
            'metode' => $request->string('metode')->toString(),
            'skor' => $setting['metode_wajah_aktif'] ? $skor : null,
            'foto' => $request->input('foto'),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'jenis' => $absensi->jenis->value,
                'waktu' => $absensi->waktu->format('H:i'),
                'status_ketepatan' => $absensi->status_ketepatan?->value,
                'daftar_presensi' => $this->absensi->daftarPresensi($event),
            ],
        ]);
    }

    protected function gagal(string $kode, string $pesan, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $kode,
            'message' => $pesan,
        ], $status);
    }
}
