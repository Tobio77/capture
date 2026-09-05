<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OverrideAbsenUmum;
use App\Http\Controllers\Controller;
use App\Models\UnitKerja;
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
 * Cakupannya mengikuti peran, sama seperti rekap: Admin UPT terkunci pada
 * unitnya sendiri dan hanya melihat pegawainya sendiri (FR-REK-02).
 */
class AbsenUmumController extends Controller
{
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
        $unitTersedia = $this->absenUmum->unitTersedia($pengguna);

        $unitId = $this->absenUmum->unitTerpilih(
            $pengguna,
            $request->integer('unit_kerja_id') ?: $unitTersedia->first()['id'] ?? null,
        );

        $tanggal = $this->tanggal($request);

        // Satu-satunya sumber baris absen umum; tab Rekap Umum memanggil yang
        // sama persis (FR-REK-01).
        $rekap = $this->absenUmum->rekapHarian(
            $pengguna,
            $unitId,
            $tanggal,
            $request->string('cari')->toString(),
        );

        $sesi = $rekap['sesi'];

        return Inertia::render('AbsenUmum/Index', [
            'unit_kerja' => $unitTersedia->values(),
            'filter' => [
                'unit_kerja_id' => $unitId,
                'tanggal' => $tanggal->toDateString(),
                'cari' => $request->string('cari')->toString(),
            ],
            'absen_umum_aktif' => $this->absenUmum->aktif(),
            'jam_masuk' => $this->setting->ambil()['jam_masuk_umum'],

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
            'riwayat' => $unitId === null ? [] : $this->absenUmum->riwayat($unitId)->values(),
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
        $pengguna = $request->user();
        $unitTersedia = $this->absenUmum->unitTersedia($pengguna);

        $unitId = $this->absenUmum->unitTerpilih(
            $pengguna,
            $request->integer('unit_kerja_id') ?: $unitTersedia->first()['id'] ?? null,
        );

        // Membuka layar berarti hendak mengabsen, jadi sesi hari ini memang
        // dibuat di sini — berbeda dari pemantauan, yang hanya membaca.
        $sesi = $unitId === null ? null : $this->absenUmum->sesi($unitId, buat: true);
        $setting = $this->setting->ambil();

        return Inertia::render('AbsenUmum/Layar', [
            'unit_kerja' => $unitTersedia->values(),
            'unit_kerja_id' => $unitId,
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
                fn (int $id) => route('absen-umum.absen.foto', [
                    'absensi' => $id,
                    'unit_kerja_id' => $unitId,
                ]),
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
        abort_unless($this->absenUmum->aktif(), 403, 'Absen umum sedang dimatikan pada Setting Absen.');

        $unitId = $this->absenUmum->unitTerpilih($request->user(), $request->integer('unit_kerja_id') ?: null);

        abort_if($unitId === null, 404, 'Unit kerja tidak dikenali.');

        $this->absenUmum->buka($unitId);

        return back()->with('sukses', 'Sesi absen umum hari ini berhasil dibuka.');
    }

    /**
     * Pasang atau cabut override buka/tutup Absen Umum hari ini (FR-SET-07).
     *
     * Override selalu menang atas jadwal, tetapi hanya untuk hari itu: ia
     * menempel pada sesi harian, sehingga besok lahir tanpa membawanya.
     */
    public function override(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'aksi' => ['required', 'in:buka,tutup,cabut'],
        ]);

        $unitId = $this->absenUmum->unitTerpilih($request->user(), $request->integer('unit_kerja_id') ?: null);

        abort_if($unitId === null, 404, 'Unit kerja tidak dikenali.');

        $override = $data['aksi'] === 'cabut'
            ? null
            : OverrideAbsenUmum::from($data['aksi']);

        $sesi = $this->absenUmum->aturOverride($unitId, $override, $request->user());

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
        $pengguna = $request->user();
        $unitId = $this->absenUmum->unitTerpilih($pengguna, $request->integer('unit_kerja_id') ?: null);

        $rekap = $this->absenUmum->rekapHarian(
            $pengguna,
            $unitId,
            $this->tanggal($request),
            $request->string('cari')->toString(),
        );

        return response()->json([
            'baris' => $rekap['baris']->values(),
            'ringkasan' => $rekap['ringkasan'],
        ]);
    }

    /**
     * Unduh rekap absen umum sebagai CSV atau PDF (FR-REK-03).
     */
    public function ekspor(Request $request): HttpResponse
    {
        $pengguna = $request->user();
        $unitId = $this->absenUmum->unitTerpilih($pengguna, $request->integer('unit_kerja_id') ?: null);
        $tanggal = $this->tanggal($request);

        $rekap = $this->absenUmum->rekapHarian(
            $pengguna,
            $unitId,
            $tanggal,
            $request->string('cari')->toString(),
        );

        $sesi = $rekap['sesi'];

        abort_if($sesi === null, 404, 'Belum ada sesi absen umum pada tanggal ini.');

        $baris = $rekap['baris'];
        $nama = 'absen-umum-'.$tanggal->format('Ymd');

        $cakupan = $pengguna->lintasUnit()
            ? (UnitKerja::query()->find($unitId)?->nama ?? 'Seluruh unit kerja')
            : ($pengguna->unitKerja?->nama ?? 'Tanpa unit kerja');

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

        return $this->ekspor->unduhCsv(
            $this->ekspor->csv(
                ['NIP', 'Nama', 'Unit Kerja', 'Jam Masuk', 'Jam Pulang', 'Metode', 'Status'],
                $baris->map(fn (array $isi) => [
                    $isi['nip'],
                    $isi['nama'],
                    $isi['unit_kerja'] ?? '',
                    $isi['jam_masuk'] ?? '',
                    $isi['jam_pulang'] ?? '',
                    $isi['metode'],
                    $isi['status_label'] ?? '',
                ]),
            ),
            "{$nama}.csv",
        );
    }

    protected function tanggal(Request $request): Carbon
    {
        $nilai = $request->string('tanggal')->toString();

        return $nilai === '' ? Carbon::today() : Carbon::parse($nilai)->startOfDay();
    }
}
