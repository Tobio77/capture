<?php

namespace App\Services;

use App\Models\EventAbsen;
use App\Models\Kiosk;
use Illuminate\Http\Request;

/**
 * Menentukan event yang sedang dilayani oleh sebuah titik absen.
 *
 * Ada dua macam titik absen, dan keduanya memakai layar serta endpoint yang
 * sama bentuknya: perangkat absen yang membawa device token, dan layar absen
 * umum yang dibuka admin di peramban sendiri.
 *
 * Sejak S29 ada dimensi kedua: MODE. Absen Event dan Absen Umum bukan lagi
 * satu layar yang diam-diam berpindah isi mengikuti ada-tidaknya kegiatan,
 * melainkan dua halaman dengan syarat akses yang berbeda:
 *
 *   - Mode `event` hanya melayani perangkat yang sudah BERGABUNG ke sebuah
 *     event lewat kode unit kerja (FR-EVT-03). Tidak ada penggabungan, tidak
 *     ada layar — betapapun unitnya tercakup event yang sedang berjalan.
 *   - Mode `umum` selalu tersedia dan tidak terikat status event apa pun.
 *     Sesi hariannya dibuka sistem saat pertama kali dibutuhkan.
 *
 * Modenya dibaca dari DEFAULT RUTE, bukan dari masukan peramban: yang
 * menentukan adalah alamat yang dibuka, dan perangkat tidak boleh dapat
 * mengaku sedang melayani event hanya dengan menambahkan satu medan pada
 * kiriman tapnya.
 *
 * Memusatkan penentuan ini di satu tempat menjaga agar pemeriksaan yang
 * melekat padanya — event masih dibuka, pegawai berada dalam cakupannya, foto
 * hanya boleh dibaca titik yang melayani event yang sama — tidak bercabang
 * menjadi beberapa versi yang bisa berbeda perilaku.
 */
class TitikAbsenService
{
    public const string MODE_EVENT = 'event';

    public const string MODE_UMUM = 'umum';

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
                'event' => $this->mode($request) === self::MODE_EVENT
                    ? $this->event->eventAktifUntukKiosk($kiosk)
                    : $this->absenUmum->sesiUntukKiosk($kiosk, buat: $buka),
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
     * Mode titik absen yang sedang melayani permintaan.
     *
     * Diambil dari default rute — nilai yang dipasang server saat mendaftarkan
     * rutenya — sehingga tidak dapat digeser oleh kiriman peramban. Apa pun
     * selain `event` diperlakukan sebagai absen umum, yang merupakan jalur
     * paling sedikit haknya.
     */
    public function mode(Request $request): string
    {
        return $request->route('mode') === self::MODE_EVENT
            ? self::MODE_EVENT
            : self::MODE_UMUM;
    }

    /**
     * URL foto pegawai yang sesuai dengan titik absen pemanggil.
     *
     * Layar yang sama dipakai beberapa konteks dengan pagar autentikasi
     * berbeda, sehingga URL foto tidak boleh dipatok ke salah satunya:
     * perangkat absen memakai rute /kiosk yang dipagari device token,
     * sedangkan layar absen umum di peramban admin memakai rute /admin yang
     * dipagari sesi.
     *
     * Pada jalur admin, `unit_kerja_id` ikut dibawa. Endpoint fotonya
     * menentukan sah-tidaknya akses dari event yang sedang dilayani titik
     * absen — dan pada jalur admin, event itu baru dapat ditentukan setelah
     * unit kerjanya diketahui. Tanpa parameter ini, gambarnya dijawab 403.
     */
    public function urlFotoPegawai(Request $request, string $nip): string
    {
        if ($request->kiosk() === null) {
            return route('absen-umum.pegawai.foto', [
                'nip' => $nip,
                'unit_kerja_id' => $this->unitTerpilih($request),
            ]);
        }

        return route("kiosk.{$this->mode($request)}.pegawai.foto", ['nip' => $nip]);
    }

    /**
     * URL foto absen; lihat catatan pada {@see self::urlFotoPegawai()}.
     */
    public function urlFotoAbsen(Request $request, int $absensiId): string
    {
        if ($request->kiosk() === null) {
            return route('absen-umum.absen.foto', [
                'absensi' => $absensiId,
                'unit_kerja_id' => $this->unitTerpilih($request),
            ]);
        }

        return route("kiosk.{$this->mode($request)}.absen.foto", ['absensi' => $absensiId]);
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
