<?php

namespace App\Http\Controllers\Absen;

use App\Exceptions\AbsenGandaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanAbsenRequest;
use App\Services\AbsensiService;
use App\Services\EventAbsenService;
use App\Services\KartuRfidService;
use App\Services\SettingAbsenService;
use App\Services\TitikAbsenService;
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
        protected TitikAbsenService $titik,
    ) {}

    public function __invoke(SimpanAbsenRequest $request): JsonResponse
    {
        ['event' => $event, 'kiosk' => $kiosk] = $this->titik->untuk($request, buka: true);

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

        try {
            $absensi = $this->absensi->catat($event, $pegawai, $kiosk, [
                'jenis' => $request->string('jenis')->toString(),
                'metode' => $request->string('metode')->toString(),
                'skor' => $setting['metode_wajah_aktif'] ? $skor : null,

                /*
                 * Foto tetap disimpan walau verifikasi wajah dimatikan: ia
                 * berfungsi sebagai bukti kehadiran, bukan hanya bahan
                 * pencocokan (revisi FR-SET-01, S28a).
                 */
                'foto' => $request->input('foto'),

                // Diisi kiosk agar absen yang tertahan antrian luring tetap
                // tercatat pada jam tapnya, bukan jam pengirimannya (NFR-05).
                'waktu_tap' => $request->input('waktu_tap'),
            ]);
        } catch (AbsenGandaException $ganda) {
            /*
             * FR-TAP-05 (revisi S28a): tap kedua untuk jenis yang sama ditolak.
             * Daftar presensi tetap dikirim supaya layar titik absen menampilkan
             * keadaan terkini, bukan berhenti pada tampilan lama.
             */
            return response()->json([
                'success' => false,
                'code' => 'SUDAH_ABSEN',
                'message' => $ganda->pesan(),
                'data' => [
                    'jenis' => $ganda->tercatat->jenis->value,
                    'waktu' => $ganda->tercatat->waktu->format('H:i'),
                    'daftar_presensi' => $this->absensi->daftarPresensi($event),
                ],
            ], 409);
        }

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
