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
 *   - `/kiosk/event` terbuka selama ada kegiatan yang sedang dibuka. Sejak
 *     S49 tidak ada lagi kode per event yang harus ditukarkan lebih dahulu:
 *     event berlaku bagi seluruh dinas, sehingga setiap perangkat yang sudah
 *     dikenali langsung melayaninya, dan keanggotaannya beserta alamat IP
 *     tercatat saat layar ini dibuka (FR-EVT-03, FR-EVT-05). Tanpa kegiatan
 *     yang dibuka, perangkat dipulangkan ke beranda — bukan disuguhi layar
 *     kosong yang tampak rusak.
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
                ->with('gagal', 'Belum ada kegiatan yang dibuka. Absen Umum tetap dapat dipakai.');
        }

        /*
         * FR-EVT-03, FR-EVT-05: perangkat yang membuka layar sebuah event
         * sudah terhitung melayaninya, tidak perlu menunggu tap pertama.
         * Barisnya lahir di sini — beserta unit asal dan alamat IP-nya —
         * sebab sejak S49 tidak ada lagi penukaran kode yang mendahuluinya.
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
             * FR-SET-07: jenis absen yang jendelanya sedang tertutup dikunci di
             * layar. Servernya tetap memeriksa ulang — layar dapat dimuat pukul
             * 08.55 lalu di-tap pukul 09.05 — tetapi mengunci tombolnya
             * mencegah orang mengantre untuk sesuatu yang pasti ditolak.
             *
             * Hanya untuk mode umum: kegiatan tidak mengenal jendela jam.
             */
            'status_jendela' => $mode === TitikAbsenService::MODE_UMUM
                ? collect($this->absenUmum->statusSemua($event))->map(fn ($s) => $s->untukLayar())
                : null,

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
