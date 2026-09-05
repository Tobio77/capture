<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Services\AbsensiService;
use App\Services\AbsenUmumService;
use App\Services\EventAbsenService;
use App\Services\SettingAbsenService;
use App\Services\TitikAbsenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Layar tap pada perangkat absen — dua panel Capture Foto & Daftar e-Presensi
 * (UIUX §4.2).
 *
 * Satu controller melayani dua mode, dan modenya ditentukan alamat yang
 * dibuka, bukan keadaan basis data (revisi S29):
 *
 *   - `/kiosk/event` hanya terbuka bagi perangkat yang sudah bergabung ke
 *     sebuah event lewat kode unit kerja (FR-EVT-03). Yang belum bergabung
 *     dipulangkan ke beranda, bukan disuguhi layar kosong yang tampak rusak.
 *   - `/kiosk/umum` selalu terbuka. Sesi hariannya boleh saja belum ada — dan
 *     memang tidak dibuat hanya karena layarnya dibuka, sebab perangkat yang
 *     menyala sepanjang hari libur tidak boleh meninggalkan sesi kosong yang
 *     kemudian terhitung sebagai hari wajib hadir pada laporan. Sesi lahir
 *     pada tap pertama.
 */
class LayarKioskController extends Controller
{
    public function __construct(
        protected EventAbsenService $event,
        protected SettingAbsenService $setting,
        protected AbsensiService $absensi,
        protected AbsenUmumService $absenUmum,
        protected TitikAbsenService $titik,
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $kiosk = $request->kiosk();
        $mode = $this->titik->mode($request);
        ['event' => $event] = $this->titik->untuk($request);

        if ($mode === TitikAbsenService::MODE_EVENT && $event === null) {
            return redirect()
                ->route('beranda')
                ->with('gagal', 'Perangkat ini belum bergabung ke event mana pun. Masukkan kode unit kerja dari admin penyelenggara.');
        }

        /*
         * FR-EVT-03: perangkat yang membuka layar sebuah event sudah terhitung
         * aktif, tidak perlu menunggu tap pertama. Ini hanya memperbarui jejak
         * — keanggotaannya sendiri sudah lahir saat kode ditukarkan.
         */
        if ($mode === TitikAbsenService::MODE_EVENT && $kiosk !== null) {
            $this->event->catatKioskAktif($event, $kiosk, $request->ip());
        }

        $setting = $this->setting->ambil();

        // Prop `kiosk` sudah dibagikan HandleInertiaRequests; jangan ditimpa
        // di sini agar bentuknya tetap sama di seluruh layar perangkat.
        return Inertia::render('Kiosk/Utama', [
            'mode' => $mode,

            /*
             * Absen umum yang dimatikan admin tidak menghalangi layar terbuka;
             * yang tidak ada hanyalah sesinya. Layar menerangkan keadaan itu
             * alih-alih menerima tap yang tidak akan tersimpan.
             */
            'absen_umum_aktif' => $this->absenUmum->aktif(),

            /*
             * FR-SET-01: metode yang dimatikan admin tidak muncul di layar
             * perangkat — kamera disembunyikan bila verifikasi wajah nonaktif,
             * dan kolom ketik disembunyikan bila input manual nonaktif.
             */
            'metode' => [
                'manual' => $setting['metode_manual_aktif'],
                'rfid' => $setting['metode_rfid_aktif'],
                'wajah' => $setting['metode_wajah_aktif'],
            ],

            /*
             * FR-SET-03: ambang kecocokan wajah dipakai modul verifikasi di
             * sisi klien. Preset kompresi ikut dikirim karena perangkatlah yang
             * menyusutkan foto sebelum mengirimkannya (FR-SET-04, S16).
             */
            'ambang_kecocokan_wajah' => $setting['ambang_kecocokan_wajah'],
            'kompresi' => $this->setting->kompresi()->rincian(),

            /*
             * FR-PEG-05 (revisi S29): selagi verifikasi wajah dimatikan, foto
             * capture pegawai yang belum punya foto referensi dipromosikan
             * menjadi foto referensinya. Layar perlu mengetahuinya untuk
             * memuat model pengenalan wajah lebih awal — pemeriksaan "tepat
             * satu wajah" memakai modul yang sama.
             */
            'daftar_wajah_otomatis' => ! $setting['metode_wajah_aktif'],

            /*
             * Jam server saat halaman dirakit. Layar memakainya untuk menyetel
             * jam berjalannya sendiri: jam perangkat titik absen kerap meleset
             * — sebagian tidak pernah disetel sejak dibeli — dan petugas yang
             * membaca jam layar harus melihat jam yang SAMA dengan yang kelak
             * tercatat pada absensi.
             */
            'waktu_server' => Carbon::now()->toIso8601String(),

            // Satu baris per pegawai; jam masuk dan pulang mengisi kolom
            // berbeda pada baris yang sama (FR-TAP-05).
            'daftar_presensi' => $event === null
                ? []
                : $this->absensi->daftarPresensi(
                    $event,
                    fn (int $id) => $this->titik->urlFotoAbsen($request, $id),
                ),

            // Null berarti tidak ada entry yang dibuka untuk titik absen ini,
            // dan layar menampilkan keadaan itu alih-alih menerima tap.
            'event' => $event === null ? null : [
                'id' => $event->id,
                'nama' => $event->nama,
                'tanggal' => $event->tanggal->toDateString(),
                'jam_mulai' => substr((string) $event->jam_mulai, 0, 5),
                'toleransi_menit' => $event->toleransi_menit,
            ],
        ]);
    }
}
