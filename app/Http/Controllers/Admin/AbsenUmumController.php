<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OverrideAbsenUmum;
use App\Exports\TabelDataExport;
use App\Http\Controllers\Controller;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\AbsensiService;
use App\Services\AbsenUmumService;
use App\Services\EksporService;
use App\Services\SettingAbsenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Absen Umum — absensi harian tanpa event kegiatan.
 *
 * Menu ini punya dua wajah. `index()` memantau sesi harian yang sedang
 * berjalan beserta rekapnya, sementara `layar()` membuka layar tangkap absen
 * di peramban admin — jalan pintas ketika tidak ada perangkat absen terpasang
 * di ruangan, misalnya pada kegiatan dadakan atau saat perangkat sedang
 * diperbaiki.
 *
 * Sejak S49 sesi hariannya SATU untuk seluruh dinas, sehingga tidak ada lagi
 * pemilih unit kerja yang menentukan sesi mana yang sedang dibuka atau
 * ditutup. Unit kerja tetap ada di layar ini, tetapi sebagai PENYARING
 * TAMPILAN rekap — dan pembatasan yang sesungguhnya tetap datang dari peran:
 * Admin UPT hanya melihat pegawainya sendiri (FR-REK-02).
 */
class AbsenUmumController extends Controller
{
    /**
     * Sama persis dengan {@see RekapController::KOLOM} — lihat catatan di
     * sana. Disalin, bukan diwarisi, karena tab kegiatan dan tab umum
     * dilayani controller yang berbeda tanpa kelas induk bersama.
     *
     * @var array<string, string>
     */
    protected const array KOLOM = [
        'nip' => 'NIP',
        'nama' => 'Nama',
        'unit_kerja' => 'Unit Kerja',
        'jam_masuk' => 'Jam Masuk',
        'jam_pulang' => 'Jam Pulang',
        'metode' => 'Metode',
        'perangkat' => 'Perangkat',
        'ip_address' => 'Alamat IP',
        'status_label' => 'Status',
    ];

    /**
     * Sama persis dengan {@see RekapController::KOLOM_WAJIB} — lihat catatan
     * di sana. Disalin, bukan diwarisi, karena kedua controller tidak berbagi
     * kelas induk.
     *
     * @var array<int, string>
     */
    protected const array KOLOM_WAJIB = ['nip', 'nama', 'jam_masuk', 'jam_pulang'];

    public function __construct(
        protected AbsenUmumService $absenUmum,
        protected AbsensiService $absensi,
        protected SettingAbsenService $setting,
        protected EksporService $ekspor,
    ) {}

    /**
     * Pemantauan sesi absen umum harian.
     */
    public function index(Request $request): Response
    {
        $pengguna = $request->user();
        $tanggal = $this->tanggal($request);
        $unitId = $this->unitFilter($request);

        // Satu-satunya sumber baris absen umum; tab Rekap Umum memanggil yang
        // sama persis (FR-REK-01).
        $rekap = $this->absenUmum->rekapHarian(
            $pengguna,
            $tanggal,
            $request->string('cari')->toString(),
            $unitId,
        );

        $sesi = $rekap['sesi'];

        return Inertia::render('AbsenUmum/Index', [
            'unit_kerja' => $this->absenUmum->unitTersedia($pengguna),
            'filter' => [
                'unit_kerja_id' => $unitId,
                'tanggal' => $tanggal->toDateString(),
                'cari' => $request->string('cari')->toString(),
            ],
            'absen_umum_aktif' => $this->absenUmum->aktif(),

            // Jam masuk hari YANG SEDANG DILIHAT ($tanggal), bukan jam global
            // seragam — bisa saja beda dari jam hari ini bila admin sedang
            // menengok tanggal lain (lihat SettingAbsenService::jadwalUntukHari()).
            'jam_masuk' => $this->setting->jadwalUntukHari($tanggal->dayOfWeekIso)['jam_masuk'],

            /*
             * Status efektif kedua jenis absen, beserta SUMBERNYA. Admin harus
             * dapat membedakan "tertutup karena di luar jam" dari "tertutup
             * karena seseorang menutupnya dan lupa mencabutnya" — keduanya
             * terlihat sama di layar tetapi menuntut tindakan berbeda.
             */
            'status_jendela' => collect($this->absenUmum->statusSemua($sesi))
                ->map(fn ($status) => $status->untukLayar()),
            'sesi' => $sesi === null ? null : [
                'id' => $sesi->id,
                'nama' => $sesi->nama,
                'tanggal' => $sesi->tanggal->toDateString(),
                'jam_mulai' => substr((string) $sesi->jam_mulai, 0, 5),
                'toleransi_menit' => $sesi->toleransi_menit,
                'aktif' => $sesi->aktif(),
            ],
            'baris' => $rekap['baris']->values(),
            'ringkasan' => $rekap['ringkasan'],
            'riwayat' => $this->absenUmum->riwayat()->values(),

            /*
             * Membuka sesi, memasang override, dan mencabutnya kini berdampak
             * pada seluruh dinas sekaligus — keputusan Admin Dinas, bukan
             * wewenang satu UPT. Admin UPT tetap memantau dan mengunduh
             * rekapnya (FR-REK-02, FR-LAP-02).
             */
            'boleh_kelola' => $pengguna->lintasUnit(),
        ]);
    }

    /**
     * Layar tangkap absen umum di peramban admin.
     *
     * Memakai layar yang sama dengan perangkat absen, hanya dengan endpoint
     * yang dipagari sesi admin alih-alih device token.
     */
    public function layar(Request $request): Response
    {
        // Membuka layar berarti hendak mengabsen, jadi sesi hari ini memang
        // dibuat di sini — berbeda dari pemantauan, yang hanya membaca.
        $sesi = $this->absenUmum->sesi(buat: true);
        $setting = $this->setting->ambil();

        return Inertia::render('AbsenUmum/Layar', [
            'absen_umum_aktif' => $this->absenUmum->aktif(),

            // FR-SET-01: metode yang dimatikan admin tidak muncul di layar.
            'metode' => [
                'manual' => $setting['metode_manual_aktif'],
                'rfid' => $setting['metode_rfid_aktif'],
                'wajah' => $setting['metode_wajah_aktif'],
            ],
            'ambang_kecocokan_wajah' => $setting['ambang_kecocokan_wajah'],
            'kompresi' => $this->setting->kompresi()->rincian(),

            // FR-PEG-05 (revisi S29); lihat catatan pada LayarKioskController.
            'daftar_wajah_otomatis' => ! $setting['metode_wajah_aktif'],

            // FR-SET-07; lihat catatan pada LayarKioskController.
            'status_jendela' => collect($this->absenUmum->statusSemua($sesi))
                ->map(fn ($status) => $status->untukLayar()),

            // Jam server, dipakai layar untuk menyetel jam berjalannya sendiri.
            'waktu_server' => Carbon::now()->toIso8601String(),

            // Layar ini dipagari sesi admin, bukan device token.
            'daftar_presensi' => $sesi === null ? [] : $this->absensi->daftarPresensi(
                $sesi,
                fn (int $id) => route('absen-umum.absen.foto', ['absensi' => $id]),
            ),
            'event' => $sesi === null ? null : [
                'id' => $sesi->id,
                'nama' => $sesi->nama,
                'tanggal' => $sesi->tanggal->toDateString(),
                'jam_mulai' => substr((string) $sesi->jam_mulai, 0, 5),
                'toleransi_menit' => $sesi->toleransi_menit,
            ],
        ]);
    }

    /**
     * Buka sesi hari ini tanpa menunggu tap pertama, agar layar pemantauan
     * langsung memperlihatkan siapa yang belum hadir.
     */
    public function buka(Request $request): RedirectResponse
    {
        abort_unless($request->user()->lintasUnit(), 403);
        abort_unless($this->absenUmum->aktif(), 403, 'Absen umum sedang dimatikan pada Setting Absen.');

        $this->absenUmum->buka();

        return back()->with('sukses', 'Sesi absen umum hari ini berhasil dibuka untuk seluruh unit kerja.');
    }

    /**
     * Pasang atau cabut override buka/tutup Absen Umum hari ini (FR-SET-07).
     *
     * Override selalu menang atas jadwal, tetapi hanya untuk hari itu: ia
     * menempel pada sesi harian, sehingga besok lahir tanpa membawanya.
     */
    public function override(Request $request): RedirectResponse
    {
        abort_unless($request->user()->lintasUnit(), 403);

        $data = $request->validate([
            'aksi' => ['required', 'in:buka,tutup,cabut'],
        ]);

        $override = $data['aksi'] === 'cabut'
            ? null
            : OverrideAbsenUmum::from($data['aksi']);

        $sesi = $this->absenUmum->aturOverride($override, $request->user());

        abort_if($sesi === null, 403, 'Absen umum sedang dimatikan pada Setting Absen.');

        return back()->with('sukses', $override === null
            ? 'Override dicabut. Absen umum kembali mengikuti jadwal.'
            : "{$override->label()} untuk hari ini. Jadwal kembali berlaku besok.");
    }

    /**
     * Rekap sesi berjalan dalam JSON, untuk penyegaran berkala tanpa memuat
     * ulang seluruh halaman.
     */
    public function data(Request $request): JsonResponse
    {
        $rekap = $this->absenUmum->rekapHarian(
            $request->user(),
            $this->tanggal($request),
            $request->string('cari')->toString(),
            $this->unitFilter($request),
        );

        return response()->json([
            'baris' => $rekap['baris']->values(),
            'ringkasan' => $rekap['ringkasan'],
        ]);
    }

    /**
     * Unduh rekap absen umum sebagai CSV, Excel, atau PDF (FR-REK-03).
     *
     * Cakupannya mengikuti peran, sama seperti yang tampil di layar: Admin UPT
     * mengunduh pegawai unitnya saja, peran lintas unit mengunduh seluruhnya
     * (FR-LAP-02). Tidak ada pagar tambahan di sini — barisnya sudah tersaring
     * sejak dirakit, sehingga tidak ada jalan memperoleh baris di luar hak
     * lewat endpoint ini.
     */
    public function ekspor(Request $request): HttpResponse
    {
        $pengguna = $request->user();
        $tanggal = $this->tanggal($request);
        $unitId = $this->unitFilter($request);

        $rekap = $this->absenUmum->rekapHarian(
            $pengguna,
            $tanggal,
            $request->string('cari')->toString(),
            $unitId,
        );

        $sesi = $rekap['sesi'];

        abort_if($sesi === null, 404, 'Belum ada sesi absen umum pada tanggal ini.');

        $baris = $rekap['baris'];
        $nama = 'absen-umum-'.$tanggal->format('Ymd');
        $cakupan = $this->namaCakupan($pengguna, $unitId);

        if ($request->string('format')->toString() === 'pdf') {
            return $this->ekspor->unduhPdf('cetak.rekap', [
                'baris' => $baris,
                'ringkasan' => $this->absensi->ringkasanRekap($baris),
                'cakupan' => $cakupan,
                'event' => [
                    'nama' => $sesi->nama,
                    'tanggal' => $sesi->tanggal->translatedFormat('l, d F Y'),
                    'jam_mulai' => substr((string) $sesi->jam_mulai, 0, 5),
                    'toleransi_menit' => $sesi->toleransi_menit,
                    'status_label' => $sesi->status->label(),
                ],
            ], "{$nama}.pdf");
        }

        $kolomAktif = $this->ekspor->kolomAktif($request, self::KOLOM, self::KOLOM_WAJIB);
        $judul = array_map(fn (string $kunci) => self::KOLOM[$kunci], $kolomAktif);
        $baris = $baris->map(fn (array $isi) => array_map(
            fn (string $kunci) => $isi[$kunci] ?? '',
            $kolomAktif,
        ));

        if ($request->string('format')->toString() === 'xlsx') {
            return Excel::download(new TabelDataExport($judul, $baris->all()), "{$nama}.xlsx");
        }

        return $this->ekspor->unduhCsv($this->ekspor->csv($judul, $baris), "{$nama}.csv");
    }

    /**
     * Unit kerja yang sedang dipakai menyaring tampilan, atau null bila tidak
     * ada penyaring.
     */
    protected function unitFilter(Request $request): ?int
    {
        return $request->integer('unit_kerja_id') ?: null;
    }

    /**
     * Kalimat cakupan pada lembar cetak: apa yang BENAR-BENAR terbaca pada
     * berkas ini, bukan hak pengguna secara umum.
     */
    protected function namaCakupan(User $pengguna, ?int $unitId): string
    {
        if ($unitId !== null) {
            return UnitKerja::query()->find($unitId)?->nama ?? 'Unit kerja terpilih';
        }

        return $pengguna->lintasUnit()
            ? 'Seluruh unit kerja'
            : ($pengguna->unitKerja?->nama ?? 'Tanpa unit kerja');
    }

    protected function tanggal(Request $request): Carbon
    {
        $nilai = $request->string('tanggal')->toString();

        return $nilai === '' ? Carbon::today() : Carbon::parse($nilai)->startOfDay();
    }
}
