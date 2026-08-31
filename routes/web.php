<?php

use App\Http\Controllers\Admin\PegawaiController;
use App\Http\Controllers\Admin\SettingWorkaController;
use App\Http\Controllers\Admin\UnitKerjaController;
use App\Http\Controllers\Auth\SesiController;
use App\Http\Controllers\Kiosk\AktivasiController;
use App\Http\Controllers\Kiosk\FotoPegawaiController;
use App\Http\Controllers\Kiosk\LayarKioskController;
use App\Http\Controllers\Kiosk\ValidasiNipController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/admin/dashboard')->name('beranda');

/*
 * Autentikasi admin (FR-AUTH-01).
 */
Route::middleware('guest')->group(function () {
    Route::get('masuk', [SesiController::class, 'create'])->name('masuk');
    Route::post('masuk', [SesiController::class, 'store']);
});

Route::post('keluar', [SesiController::class, 'destroy'])->middleware('auth')->name('keluar');

/*
 * Perangkat kiosk. Autentikasi memakai device_token per perangkat, bukan akun
 * personal pegawai (FR-AUTH-01, NFR-03).
 */
Route::prefix('kiosk')->name('kiosk.')->group(function () {
    Route::get('aktivasi', [AktivasiController::class, 'create'])->name('aktivasi');
    Route::post('aktivasi', [AktivasiController::class, 'store'])->middleware('throttle:10,1');

    Route::middleware('kiosk')->group(function () {
        Route::get('/', LayarKioskController::class)->name('utama');
        Route::post('lepas', [AktivasiController::class, 'destroy'])->name('lepas');

        // Validasi NIP dijawab dari basis data lokal — satu tap tidak boleh
        // bergantung pada tersedianya jaringan ke WORKA (FR-TAP-03).
        Route::post('tap/validasi-nip', ValidasiNipController::class)
            ->middleware('throttle:120,1')
            ->name('tap.validasi-nip');

        Route::get('pegawai/{nip}/foto', FotoPegawaiController::class)
            ->where('nip', '[0-9]{8,20}')
            ->middleware('throttle:300,1')
            ->name('pegawai.foto');
    });
});

/*
 * Panel Admin. Prefix /admin mengikuti daftar endpoint pada
 * docs/03-SDD-Absensi.md §4; pembatasan menu per peran mengikuti matriks
 * pada docs/02-SRS-Absensi.md §6 (FR-AUTH-02).
 */
Route::middleware(['auth', 'pengguna.aktif'])->prefix('admin')->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::prefix('kelola-absen')->group(function () {
        Route::inertia('event', 'Segera', [
            'judul' => 'Daftar Event',
            'deskripsi' => 'Pembuatan dan pengelolaan event absensi. Dikerjakan pada Sesi S10.',
        ])->name('event.index');

        Route::inertia('rekap', 'Segera', [
            'judul' => 'Rekap Absen',
            'deskripsi' => 'Daftar e-presensi per event yang diperbarui secara live. Dikerjakan pada Sesi S21.',
        ])->name('rekap.index');

        // Setting Absen adalah pengaturan global sistem — tidak untuk Admin UPT.
        Route::inertia('setting', 'Segera', [
            'judul' => 'Setting Absen',
            'deskripsi' => 'Metode absen, toleransi default, ambang kecocokan wajah, dan kompresi foto. Dikerjakan pada Sesi S09.',
        ])->middleware('peran:superadmin,admin_dinas')->name('setting-absen.index');

        /*
         * Setting Unit Kerja (FR-UNIT-01, FR-UNIT-02).
         * Admin UPT boleh melihat daftarnya, tetapi tidak boleh mengubah (SRS §6).
         */
        Route::get('unit-kerja', [UnitKerjaController::class, 'index'])->name('unit-kerja.index');

        Route::middleware('peran:superadmin,admin_dinas')->group(function () {
            Route::post('unit-kerja', [UnitKerjaController::class, 'store'])->name('unit-kerja.store');
            Route::patch('unit-kerja/{unit_kerja}', [UnitKerjaController::class, 'update'])->name('unit-kerja.update');
            Route::patch('unit-kerja/{unit_kerja}/status', [UnitKerjaController::class, 'ubahStatus'])->name('unit-kerja.status');
        });
    });

    /*
     * Kelola Pegawai (FR-PEG-01 s.d. FR-PEG-04). Data pegawai baca-saja;
     * satu-satunya aksi tulis adalah memicu sinkronisasi dari WORKA, dan itu
     * pun terbatas pada peran lintas unit.
     */
    Route::get('pegawai', [PegawaiController::class, 'index'])->name('pegawai.index');
    Route::get('pegawai/status', [PegawaiController::class, 'statusSinkron'])->name('pegawai.status');

    Route::middleware('peran:superadmin,admin_dinas')->group(function () {
        Route::post('pegawai/sinkron', [PegawaiController::class, 'sinkron'])
            ->middleware('throttle:6,1')
            ->name('pegawai.sinkron');

        /*
         * Setting → Integrasi WORKA. Token API bersifat sistem-wide,
         * jadi hanya peran lintas unit yang boleh menyentuhnya.
         */
        Route::get('setting/worka', [SettingWorkaController::class, 'edit'])->name('setting-worka.edit');
        Route::post('setting/worka', [SettingWorkaController::class, 'update'])->name('setting-worka.update');
        Route::post('setting/worka/test', [SettingWorkaController::class, 'uji'])->name('setting-worka.uji');
    });

    Route::inertia('pengguna', 'Segera', [
        'judul' => 'Kelola User / Role',
        'deskripsi' => 'Akun admin dan akun kiosk beserta penerbitan kode aktivasi. Dikerjakan pada Sesi S23 dan S24.',
    ])->middleware('peran:superadmin,admin_dinas')->name('pengguna.index');

    Route::inertia('laporan', 'Segera', [
        'judul' => 'Laporan',
        'deskripsi' => 'Laporan kehadiran per periode dan unit kerja beserta ekspor. Dikerjakan pada Sesi S22.',
    ])->name('laporan.index');
});
