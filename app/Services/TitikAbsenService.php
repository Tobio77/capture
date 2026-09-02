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
     * URL foto pegawai yang sesuai dengan titik absen pemanggil.
     *
     * Layar yang sama dipakai dua konteks dengan pagar autentikasi berbeda,
     * sehingga URL foto tidak boleh dipatok ke salah satunya: perangkat absen
     * memakai rute /kiosk yang dipagari device token, sedangkan layar absen
     * umum di peramban admin memakai rute /admin yang dipagari sesi.
     *
     * Pada jalur admin, `unit_kerja_id` ikut dibawa. Endpoint fotonya
     * menentukan sah-tidaknya akses dari event yang sedang dilayani titik
     * absen — dan pada jalur admin, event itu baru dapat ditentukan setelah
     * unit kerjanya diketahui. Tanpa parameter ini, gambarnya dijawab 403.
     */
    public function urlFotoPegawai(Request $request, string $nip): string
    {
        return $request->kiosk() !== null
            ? route('kiosk.pegawai.foto', ['nip' => $nip])
            : route('absen-umum.pegawai.foto', [
                'nip' => $nip,
                'unit_kerja_id' => $this->unitTerpilih($request),
            ]);
    }

    /**
     * URL foto absen; lihat catatan pada {@see self::urlFotoPegawai()}.
     */
    public function urlFotoAbsen(Request $request, int $absensiId): string
    {
        return $request->kiosk() !== null
            ? route('kiosk.absen.foto', ['absensi' => $absensiId])
            : route('absen-umum.absen.foto', [
                'absensi' => $absensiId,
                'unit_kerja_id' => $this->unitTerpilih($request),
            ]);
    }

    /**
     * Unit kerja yang sedang dilayani layar absen umum di peramban admin.
     */
    protected function unitTerpilih(Request $request): ?int
    {
        $pengguna = $request->user();

        return $pengguna === null
            ? null
            : $this->absenUmum->unitTerpilih($pengguna, $request->integer('unit_kerja_id') ?: null);
    }
}
