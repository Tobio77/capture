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
 * Dimensi keduanya adalah MODE. Absen Event dan Absen Umum bukan satu layar
 * yang diam-diam berpindah isi mengikuti ada-tidaknya kegiatan, melainkan dua
 * halaman dengan syarat akses yang berbeda:
 *
 *   - Mode `event` melayani kegiatan yang sedang dibuka. Sejak S49 tidak ada
 *     lagi kode per event yang harus ditukarkan lebih dahulu: event berlaku
 *     bagi seluruh dinas, sehingga setiap perangkat yang sudah dikenali
 *     ({@see KodeUnitService}) langsung melayaninya. Tanpa kegiatan yang
 *     dibuka, tidak ada layar.
 *   - Mode `umum` selalu tersedia dan tidak terikat status event apa pun.
 *     Sesi hariannya — satu untuk seluruh dinas — dibuka sistem saat pertama
 *     kali dibutuhkan.
 *
 * Modenya dibaca dari DEFAULT RUTE, bukan dari masukan peramban: yang
 * menentukan adalah alamat yang dibuka, dan perangkat tidak boleh dapat
 * mengaku sedang melayani event hanya dengan menambahkan satu medan pada
 * kiriman tapnya.
 *
 * Memusatkan penentuan ini di satu tempat menjaga agar pemeriksaan yang
 * melekat padanya — event masih dibuka, foto hanya boleh dibaca titik yang
 * melayani event yang sama — tidak bercabang menjadi beberapa versi yang bisa
 * berbeda perilaku.
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
        return [
            'event' => $this->mode($request) === self::MODE_EVENT
                ? $this->event->eventAktifSekarang()
                : $this->absenUmum->sesi(buat: $buka),

            /*
             * Layar admin bukan perangkat terdaftar; absensinya tercatat tanpa
             * kiosk_id, persis seperti perangkat yang kemudian dilepas.
             * `Request::kiosk()` mengembalikan null di sana.
             */
            'kiosk' => $request->kiosk(),
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
     * Pesan penolakan yang tepat saat {@see self::untuk()} mengembalikan
     * `event: null` — dipakai IdentifikasiTapController dan
     * SimpanAbsenController.
     *
     * Kode kegagalan `EVENT_TIDAK_AKTIF` dipakai KEDUA mode, tetapi akar
     * masalahnya berbeda sama sekali di antara keduanya — dan sebelum
     * perbaikan ini, kedua controller memberi pesan yang sama persis pada
     * KEDUA mode. Itu benar untuk mode event, tetapi MENYESATKAN untuk mode
     * umum: Absen Umum tidak pernah bergantung pada event kegiatan sama
     * sekali (lihat docblock kelas ini), sehingga operator yang membaca pesan
     * itu di layar Absen Umum wajar mengira sebaliknya. Untuk mode umum,
     * satu-satunya alasan {@see AbsenUmumService::sesi()} menolak membuka sesi
     * baru adalah sakelar Absen Umum yang sedang dimatikan admin.
     */
    public function pesanTidakAda(Request $request): string
    {
        return $this->mode($request) === self::MODE_UMUM
            ? 'Absen Umum sedang dimatikan oleh admin pada Setting Absen.'
            : 'Tidak ada kegiatan yang sedang dibuka.';
    }

    /**
     * Cakupan unit untuk Daftar e-Presensi pada titik absen pemanggil.
     *
     * Perangkat absen melayani seluruh dinas, jadi selalu null. Layar absen
     * di peramban admin mengikuti cakupan admin yang membukanya: Admin UPT
     * hanya melihat pegawai unitnya sendiri.
     *
     * @return array<int, int>|null
     */
    public function cakupanPresensi(Request $request): ?array
    {
        if ($request->kiosk() !== null) {
            return null;
        }

        return $request->user()?->cakupanUnit();
    }

    /**
     * URL foto pegawai yang sesuai dengan titik absen pemanggil.
     *
     * Layar yang sama dipakai beberapa konteks dengan pagar autentikasi
     * berbeda, sehingga URL foto tidak boleh dipatok ke salah satunya:
     * perangkat absen memakai rute /kiosk yang dipagari device token,
     * sedangkan layar absen umum di peramban admin memakai rute /admin yang
     * dipagari sesi.
     */
    public function urlFotoPegawai(Request $request, string $nip): string
    {
        if ($request->kiosk() === null) {
            return route('absen-umum.pegawai.foto', ['nip' => $nip]);
        }

        return route("kiosk.{$this->mode($request)}.pegawai.foto", ['nip' => $nip]);
    }

    /**
     * URL foto absen; lihat catatan pada {@see self::urlFotoPegawai()}.
     */
    public function urlFotoAbsen(Request $request, int $absensiId): string
    {
        if ($request->kiosk() === null) {
            return route('absen-umum.absen.foto', ['absensi' => $absensiId]);
        }

        return route("kiosk.{$this->mode($request)}.absen.foto", ['absensi' => $absensiId]);
    }
}
