<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Kiosk\AktivasiController;
use App\Services\AbsenUmumService;
use App\Services\EventAbsenService;
use App\Services\KioskService;
use App\Services\SettingAbsenService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman depan aplikasi — satu-satunya pintu masuk (S30).
 *
 * Sampai S29 aplikasi tidak punya halaman depan sama sekali: `/` melempar ke
 * dashboard admin, sehingga pegawai yang membuka alamatnya mendarat di layar
 * login yang bukan untuknya. Petugas titik absen pun harus tahu alamat
 * `/kiosk` untuk sampai ke tempat yang benar.
 *
 * Halaman ini menggantikan keduanya, dan sengaja **tidak** dipagari apa pun:
 * ia harus terbuka bagi mesin yang belum pernah dikenali. Yang berubah hanyalah
 * apa yang ditawarkannya, mengikuti keadaan perangkat yang membukanya.
 *
 * | Keadaan                        | Absen Umum        | Absen Event                 |
 * |--------------------------------|-------------------|-----------------------------|
 * | Perangkat belum dikenali       | ke layar masuk    | ke layar masuk              |
 * | Sudah dikenali, ada kegiatan   | langsung masuk    | langsung masuk              |
 * | Sudah dikenali, tanpa kegiatan | langsung masuk    | tertutup, dengan keterangan |
 *
 * **Sejak S49 tidak ada lagi kolom kode event di sini.** Kode kini menempel
 * pada unit kerja dan hanya diketikkan sekali, di layar masuk perangkat
 * ({@see AktivasiController}); sesudah itu
 * perangkat langsung melayani kegiatan apa pun yang sedang dibuka, sebab event
 * selalu berlaku bagi seluruh dinas.
 */
class BerandaController extends Controller
{
    public function __construct(
        protected KioskService $kiosk,
        protected EventAbsenService $event,
        protected AbsenUmumService $absenUmum,
        protected SettingAbsenService $setting,
    ) {}

    public function __invoke(Request $request): Response
    {
        $perangkat = $this->kiosk->kioskDariToken($request->cookie(KioskService::NAMA_COOKIE));

        /*
         * Kegiatan yang sedang dibuka hanya diberitahukan kepada perangkat
         * yang sudah dikenali. Nama kegiatan adalah keterangan internal, dan
         * tidak ada alasan membocorkannya kepada mesin mana pun yang kebetulan
         * dapat menjangkau alamat server.
         */
        $event = $perangkat === null ? null : $this->event->eventAktifSekarang();
        $setting = $this->setting->ambil();

        return Inertia::render('Beranda', [
            /*
             * Perangkat yang membuka halaman ini, bila sudah dikenali.
             * Tidak memakai prop `kiosk` yang dibagikan HandleInertiaRequests:
             * prop itu diisi middleware `kiosk`, yang justru tidak berlaku di
             * sini — halaman depan harus terbuka tanpa device token.
             */
            'perangkat' => $perangkat === null ? null : [
                'nama_titik' => $perangkat->nama_titik,

                /*
                 * Hanya namanya. Kode unit ('DISNAKER') adalah penanda
                 * internal untuk admin; bagi orang yang berdiri di depan
                 * layar ini ia hanya deretan huruf tanpa arti, dan tidak
                 * pernah ada tindakan yang bergantung padanya di sini.
                 */
                'unit_kerja' => $perangkat->unitKerja?->only(['nama']),
            ],

            // Null berarti tidak ada kegiatan yang sedang dibuka; layar Absen
            // Event menerangkan keadaan itu alih-alih menawarkan pintu buntu.
            'event_aktif' => $event === null ? null : [
                'id' => $event->id,
                'nama' => $event->nama,
                'tanggal' => $event->tanggal->toDateString(),
                'jam_mulai' => substr((string) $event->jam_mulai, 0, 5),

                // Dipakai layar depan menghitung batas tepat waktu kegiatan,
                // yang menggantikan batas harian selama perangkat melayaninya.
                'toleransi_menit' => $event->toleransi_menit,
            ],

            /*
             * Sakelar fitur di Setting Absen. Fitur yang dinonaktifkan tetap
             * tampil sebagai pilihan yang terkunci beserta keterangannya —
             * menyembunyikannya justru membuat petugas mengira perangkatnya
             * rusak — sementara layarnya sendiri ditutup
             * PastikanFiturAbsenAktif.
             */
            'absen_umum_aktif' => (bool) $setting['absen_umum_aktif'],
            'absen_event_aktif' => (bool) $setting['absen_event_aktif'],

            /*
             * FR-SET-06. Menentukan bunyi ajakan pada perangkat yang belum
             * dikenali: dengan mode pendaftaran mati, ia cukup mengetikkan kode
             * unit kerjanya; dengan mode itu menyala, ia perlu kode aktivasi
             * yang diterbitkan admin untuk mesin itu sendiri.
             */
            'mode_pendaftaran' => (bool) $setting['pendaftaran_perangkat_aktif'],

            /*
             * Jam server saat halaman dirakit. Jam raksasa di layar depan
             * menyetel dirinya dari sini, bukan dari jam perangkat — lihat
             * catatan pada useJamServer.
             */
            'waktu_server' => Carbon::now()->toIso8601String(),

            /*
             * Jam masuk dan toleransi yang berlaku hari ini (FR-SET-02).
             * Ditampilkan satu baris di bawah tanggal supaya angka jam
             * raksasa itu punya konsekuensi — orang yang membacanya langsung
             * tahu ia masih tepat waktu atau sudah lewat.
             */
            'jam_masuk' => $this->setting->jadwalUntukHari(Carbon::now()->dayOfWeekIso)['jam_masuk'],
            'toleransi_menit' => $setting['toleransi_default_menit'],
        ]);
    }
}
