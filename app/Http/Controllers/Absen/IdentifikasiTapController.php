<?php

namespace App\Http\Controllers\Absen;

use App\Enums\JenisAbsen;
use App\Http\Controllers\Controller;
use App\Services\AbsensiService;
use App\Services\EventAbsenService;
use App\Services\KartuRfidService;
use App\Services\SettingAbsenService;
use App\Services\TitikAbsenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mengenali pegawai dari tap kartu RFID atau ketikan manual (FR-TAP-03).
 *
 * Kolom tap menerima dua bentuk masukan yang tidak dapat dibedakan dari sisi
 * peramban: UID kartu yang "diketikkan" reader HID, dan NIP yang diketik
 * pegawai sendiri. Keduanya dikirim apa adanya sebagai `id_card`, lalu
 * diselesaikan KartuRfidService.
 *
 * Pencarian dilakukan pada basis data LOKAL, bukan memanggil WORKA:
 * satu tap tidak boleh bergantung pada tersedianya jaringan ke WORKA,
 * dan data pegawai sudah disinkronkan secara terjadwal.
 */
class IdentifikasiTapController extends Controller
{
    public function __construct(
        protected AbsensiService $absensi,
        protected EventAbsenService $event,
        protected KartuRfidService $kartu,
        protected SettingAbsenService $setting,
        protected TitikAbsenService $titik,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_card' => ['required', 'string', 'max:32'],
        ]);

        /*
         * FR-EVT-04: begitu event ditutup, tap baru ditolak. Titik absen tidak
         * menyebutkan event mana yang dimaksud — FR-EVT-06 menjamin paling
         * banyak satu event kegiatan aktif per unit, sehingga server yang
         * menentukan. Tidak ada event aktif berarti tidak ada yang dapat
         * diabsen; sesi absen umum harian dibuka di sini bila memang belum ada.
         */
        ['event' => $event, 'kiosk' => $kiosk] = $this->titik->untuk($request, buka: true);

        if ($event === null) {
            return response()->json([
                'success' => false,
                'code' => 'EVENT_TIDAK_AKTIF',
                'message' => 'Tidak ada event yang sedang dibuka untuk unit kerja ini.',
            ], 409);
        }

        // FR-EVT-03: kiosk tercatat sebagai terhubung pada event ini, beserta
        // alamat IP-nya, terhitung sejak tap pertama yang dilayaninya. Layar
        // absen di peramban admin bukan perangkat terdaftar, jadi dilewati.
        if ($kiosk !== null) {
            $this->event->catatKioskAktif($event, $kiosk, $request->ip());
        }

        $pegawai = $this->kartu->kenali($data['id_card']);

        if ($pegawai === null) {
            return response()->json([
                'success' => false,
                'code' => 'ID_TIDAK_DIKENAL',
                'message' => 'Kartu atau NIP tidak terdaftar dalam sistem.',
            ], 404);
        }

        /*
         * Pegawai nonaktif dijawab gagal, bukan sukses bertanda aktif=false —
         * kiosk memutuskan lanjut atau tidak dari medan `success` saja.
         */
        if (! $pegawai->aktif) {
            return response()->json([
                'success' => false,
                'code' => 'PEGAWAI_TIDAK_AKTIF',
                'message' => 'Pegawai tidak aktif.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                // Event yang akan menampung tap ini, ditentukan server.
                'event' => [
                    'id' => $event->id,
                    'nama' => $event->nama,
                    'jam_mulai' => substr((string) $event->jam_mulai, 0, 5),
                    'toleransi_menit' => $event->toleransi_menit,
                ],
                /*
                 * FR-TAP-05 (revisi S28a): jenis yang sudah tercatat diberitahukan
                 * sejak identifikasi, sehingga kamera tidak perlu menyala hanya
                 * untuk berakhir ditolak — dan pegawai langsung membaca pukul
                 * berapa ia sebenarnya sudah absen.
                 */
                'sudah_absen' => [
                    'datang' => $this->absensi
                        ->absenTercatat($event, $pegawai, JenisAbsen::Datang)
                        ?->waktu->format('H:i'),
                    'pulang' => $this->absensi
                        ->absenTercatat($event, $pegawai, JenisAbsen::Pulang)
                        ?->waktu->format('H:i'),
                ],

                'nip' => $pegawai->nip,
                'nama' => $pegawai->nama,
                'jabatan' => $pegawai->jabatan,

                // Kiosk menandai tap yang datang dari kartu terdaftar, agar
                // metode absen tercatat benar saat penyimpanan (S16).
                'metode' => $pegawai->uid_kartu !== null
                    && KartuRfidService::normalkan($data['id_card']) === $pegawai->uid_kartu
                        ? 'rfid'
                        : 'manual',
                'unit_kerja_kode' => $pegawai->unitKerja?->kode,
                'unit_kerja_nama' => $pegawai->unitKerja?->nama,

                /*
                 * Unit kerja pegawai berbeda dari titik absen — layar memakai
                 * ini untuk menandai peserta dari luar unit pada event lintas
                 * unit. Perangkat membandingkannya dengan unit tempat ia
                 * dipasang; layar absen umum di peramban admin tidak menaut ke
                 * perangkat mana pun, sehingga pembandingnya adalah cakupan
                 * event yang sedang dilayani.
                 */
                'unit_kerja_sama' => $kiosk !== null
                    ? $pegawai->unit_kerja_id === $kiosk->unit_kerja_id
                    : in_array($pegawai->unit_kerja_id, $this->event->unitTercakup($event), true),

                // Foto berasal dari WORKA dan disajikan lewat proxy SI-ABSEN,
                // sehingga token WORKA tidak pernah sampai ke browser kiosk.
                'foto_tersedia' => $pegawai->foto_tersedia_worka,
                'foto_url' => route(
                    $this->titik->ruteFotoPegawai($request),
                    ['nip' => $pegawai->nip],
                ),

                // Penanda milik SI-ABSEN sendiri (S08), terpisah dari foto WORKA.
                'wajah_terdaftar' => $pegawai->wajah_terdaftar,

                /*
                 * Embedding referensi milik pegawai yang di-tap saja, bukan
                 * seluruh unit: pencocokan bersifat 1:1 (SDD §3), sehingga
                 * mengirimkan satu deskriptor sudah cukup dan biometrik
                 * pegawai lain tidak perlu berada di browser kiosk.
                 *
                 * Tidak dikirim sama sekali ketika admin mematikan verifikasi
                 * wajah: tidak ada gunanya menaruh data biometrik di perangkat
                 * yang memang tidak akan memakainya.
                 *
                 * Foto referensinya sendiri tidak pernah ikut dikirim.
                 */
                'embedding_wajah' => $this->setting->ambil()['metode_wajah_aktif']
                    ? $pegawai->embedding_wajah
                    : null,
            ],
        ]);
    }
}
