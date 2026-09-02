<?php

namespace App\Services;

use App\Models\EventAbsen;
use App\Models\Kiosk;
use Illuminate\Http\Request;

/**
 * Menentukan event yang sedang dilayani oleh sebuah titik absen.
 *
 * Ada dua macam titik absen, dan keduanya memakai layar serta endpoint yang
 * sama: perangkat absen yang membawa device token, dan layar absen umum yang
 * dibuka admin di peramban sendiri. Yang membedakannya hanya cara event
 * ditentukan — perangkat mengikuti unit tempat ia dipasang, sedangkan layar
 * admin mengikuti unit yang dipilih (atau, bagi Admin UPT, unitnya sendiri).
 *
 * Memusatkan penentuan ini di satu tempat menjaga agar pemeriksaan yang
 * melekat padanya — event masih dibuka, pegawai berada dalam cakupannya,
 * foto hanya boleh dibaca titik yang melayani event yang sama — tidak
 * bercabang menjadi dua versi yang bisa berbeda perilaku.
 */
class TitikAbsenService
{
    public function __construct(
        protected EventAbsenService $event,
        protected AbsenUmumService $absenUmum,
    ) {}

    /**
     * Event dan perangkat yang melayani permintaan ini.
     *
     * `$buka` menyalakan pembuatan sesi absen umum: hanya jalur yang memang
     * hendak mencatat kehadiran yang membukanya, sehingga polling daftar
     * presensi tidak meninggalkan sesi kosong pada hari libur.
     *
     * @return array{event: ?EventAbsen, kiosk: ?Kiosk}
     */
    public function untuk(Request $request, bool $buka = false): array
    {
        $kiosk = $request->kiosk();

        if ($kiosk !== null) {
            return [
                'event' => $this->event->eventAktifUntukKiosk($kiosk, bukaAbsenUmum: $buka),
                'kiosk' => $kiosk,
            ];
        }

        $pengguna = $request->user();

        // Bukan perangkat dan bukan admin: tidak ada titik absen yang sah.
        if ($pengguna === null) {
            return ['event' => null, 'kiosk' => null];
        }

        return [
            'event' => $this->absenUmum->sesiUntukAdmin(
                $pengguna,
                $request->integer('unit_kerja_id') ?: null,
                buat: $buka,
            ),

            // Layar admin bukan perangkat terdaftar; absensinya tercatat tanpa
            // kiosk_id, persis seperti perangkat yang kemudian dilepas.
            'kiosk' => null,
        ];
    }

    /**
     * Nama rute foto pegawai yang sesuai dengan titik absen pemanggil.
     *
     * Layar yang sama dipakai dua konteks dengan pagar autentikasi berbeda,
     * sehingga URL foto tidak boleh dipatok ke salah satunya.
     */
    public function ruteFotoPegawai(Request $request): string
    {
        return $request->kiosk() === null ? 'absen-umum.pegawai.foto' : 'kiosk.pegawai.foto';
    }

    public function ruteFotoAbsen(Request $request): string
    {
        return $request->kiosk() === null ? 'absen-umum.absen.foto' : 'kiosk.absen.foto';
    }
}
