<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Services\AbsensiService;
use App\Services\EventAbsenService;
use App\Services\SettingAbsenService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LayarKioskController extends Controller
{
    public function __construct(
        protected EventAbsenService $event,
        protected SettingAbsenService $setting,
        protected AbsensiService $absensi,
    ) {}

    /**
     * Layar utama kiosk. Dua panel Capture Foto & Daftar e-Presensi
     * dibangun pada Sesi S13.
     */
    public function __invoke(Request $request): Response
    {
        $kiosk = $request->kiosk();
        $event = $kiosk === null ? null : $this->event->eventAktifUntukKiosk($kiosk);

        /*
         * FR-EVT-03: kiosk yang membuka layar untuk sebuah event sudah
         * terhitung terhubung, tidak perlu menunggu tap pertama.
         */
        if ($event !== null && $kiosk !== null) {
            $this->event->catatKioskAktif($event, $kiosk, $request->ip());
        }

        $setting = $this->setting->ambil();

        // Prop `kiosk` sudah dibagikan HandleInertiaRequests; jangan ditimpa
        // di sini agar bentuknya tetap sama di seluruh layar kiosk.
        return Inertia::render('Kiosk/Utama', [
            /*
             * FR-SET-01: metode yang dimatikan admin tidak muncul di layar
             * kiosk — kamera disembunyikan bila verifikasi wajah nonaktif,
             * dan kolom ketik disembunyikan bila input manual nonaktif.
             */
            'metode' => [
                'manual' => $setting['metode_manual_aktif'],
                'rfid' => $setting['metode_rfid_aktif'],
                'wajah' => $setting['metode_wajah_aktif'],
            ],

            /*
             * FR-SET-03: ambang kecocokan wajah dipakai modul verifikasi di
             * sisi klien. Preset kompresi ikut dikirim karena kiosk yang
             * menyusutkan foto sebelum mengirimkannya (FR-SET-04, S16).
             */
            'ambang_kecocokan_wajah' => $setting['ambang_kecocokan_wajah'],
            'kompresi' => $this->setting->kompresi()->rincian(),

            // Satu baris per pegawai; jam masuk dan pulang mengisi kolom
            // berbeda pada baris yang sama (FR-TAP-05).
            'daftar_presensi' => $event === null
                ? []
                : $this->absensi->daftarPresensi($event),

            // Null berarti tidak ada entry yang dibuka untuk unit ini, dan
            // layar kiosk menampilkan keadaan itu alih-alih menerima tap.
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
