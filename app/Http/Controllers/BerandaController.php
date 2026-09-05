<?php

namespace App\Http\Controllers;

use App\Models\EventAbsen;
use App\Services\AbsenUmumService;
use App\Services\KioskService;
use App\Services\KodeUnitEventService;
use App\Services\SettingAbsenService;
use Illuminate\Http\Request;
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
 * ia harus terbuka bagi mesin yang belum pernah diaktifkan. Yang berubah
 * hanyalah apa yang ditawarkannya, mengikuti keadaan perangkat yang membukanya.
 *
 * | Keadaan                       | Absen Umum          | Absen Event                    |
 * |-------------------------------|---------------------|--------------------------------|
 * | Perangkat belum diaktifkan    | ke layar aktivasi   | ke layar aktivasi              |
 * | Sudah aktif, belum ikut event | langsung masuk      | daftar event + kolom kode      |
 * | Sudah aktif dan ikut event    | langsung masuk      | langsung masuk                 |
 *
 * **Daftar event hanya dikirim kepada perangkat yang sudah diaktifkan.**
 * Mengikuti keputusan yang sama pada layar aktivasi (FR-SET-06): nama kegiatan
 * beserta unit penyelenggaranya adalah keterangan internal, dan tidak ada
 * alasan membocorkannya kepada mesin mana pun yang kebetulan dapat menjangkau
 * alamat server. Kodenya sendiri tetap menjadi penentu, tetapi daftar ini
 * mempersempit tebakan — jadi ia ikut dipagari.
 */
class BerandaController extends Controller
{
    public function __construct(
        protected KioskService $kiosk,
        protected KodeUnitEventService $kode,
        protected AbsenUmumService $absenUmum,
        protected SettingAbsenService $setting,
    ) {}

    public function __invoke(Request $request): Response
    {
        $perangkat = $this->kiosk->kioskDariToken($request->cookie(KioskService::NAMA_COOKIE));
        $event = $perangkat === null ? null : $this->kode->eventYangDiikuti($perangkat);

        return Inertia::render('Beranda', [
            /*
             * Perangkat yang membuka halaman ini, bila sudah diaktifkan.
             * Tidak memakai prop `kiosk` yang dibagikan HandleInertiaRequests:
             * prop itu diisi middleware `kiosk`, yang justru tidak berlaku di
             * sini — halaman depan harus terbuka tanpa device token.
             */
            'perangkat' => $perangkat === null ? null : [
                'nama_titik' => $perangkat->nama_titik,
                'unit_kerja' => $perangkat->unitKerja?->only(['id', 'kode', 'nama']),
            ],

            // Null berarti perangkat belum bergabung ke event mana pun.
            'event_diikuti' => $event === null ? null : [
                'id' => $event->id,
                'nama' => $event->nama,
                'jam_mulai' => substr((string) $event->jam_mulai, 0, 5),
            ],

            'event_aktif' => $perangkat === null ? [] : $this->eventAktif(),

            /*
             * Absen Umum tetap ditawarkan walau dimatikan admin — layarnya
             * yang menerangkan keadaan itu. Menyembunyikan pintasannya justru
             * membuat petugas mengira perangkatnya rusak.
             */
            'absen_umum_aktif' => $this->absenUmum->aktif(),

            /*
             * FR-SET-06. Menentukan bunyi ajakan pada perangkat yang belum
             * aktif: dengan Mode Terbuka ia cukup memilih unit kerjanya,
             * tanpa Mode Terbuka ia perlu kode aktivasi dari admin.
             *
             * Sengaja TIDAK dinamai `mode_terbuka`: nama itu sudah dipakai
             * prop bersama HandleInertiaRequests untuk spanduk peringatan di
             * Panel Admin, yang hanya berlaku bagi sesi admin. Dua arti pada
             * satu nama akan menyesatkan pembaca berikutnya.
             */
            'aktivasi_tanpa_kode' => $this->setting->modeTerbuka(),

            'panjang_kode' => KodeUnitEventService::PANJANG_KODE,
        ]);
    }

    /**
     * Event kegiatan yang sedang dibuka, sebagai keterangan bagi petugas yang
     * hendak memasukkan kode.
     *
     * Kodenya sendiri tidak pernah ikut: yang membedakan petugas yang berhak
     * dari yang tidak justru kode itu.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function eventAktif(): array
    {
        return EventAbsen::query()
            ->aktif()
            ->kegiatan()
            ->with('unitKerja:id,kode,nama')
            ->orderBy('tanggal')
            ->orderBy('jam_mulai')
            ->get()
            ->map(fn (EventAbsen $event) => [
                'id' => $event->id,
                'nama' => $event->nama,
                'tanggal' => $event->tanggal->toDateString(),
                'jam_mulai' => substr((string) $event->jam_mulai, 0, 5),
                'cakupan_label' => $event->berlakuUntukSemuaUnit()
                    ? $event->cakupan->label()
                    : $event->unitKerja->pluck('kode')->implode(', '),
            ])
            ->all();
    }
}
