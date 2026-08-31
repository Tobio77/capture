<?php

use App\Http\Controllers\Admin\UnitKerjaController;
use App\Http\Controllers\Auth\SesiController;
use App\Http\Controllers\Kiosk\AktivasiController;
use App\Http\Controllers\Kiosk\LayarKioskController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard')->name('beranda');

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
    });
});

/*
 * Panel Admin. Pembatasan menu per peran mengikuti matriks pada
 * docs/02-SRS-Absensi.md §6 (FR-AUTH-02).
 */
Route::middleware(['auth', 'pengguna.aktif'])->group(function () {
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

    Route::inertia('pegawai', 'Segera', [
        'judul' => 'Kelola Pegawai',
        'deskripsi' => 'Data pegawai hasil sinkronisasi WORKA/BKD dan status foto referensi wajah. Dikerjakan pada Sesi S07 dan S08.',
    ])->name('pegawai.index');

    Route::inertia('pengguna', 'Segera', [
        'judul' => 'Kelola User / Role',
        'deskripsi' => 'Akun admin dan akun kiosk beserta penerbitan kode aktivasi. Dikerjakan pada Sesi S23 dan S24.',
    ])->middleware('peran:superadmin,admin_dinas')->name('pengguna.index');

    Route::inertia('laporan', 'Segera', [
        'judul' => 'Laporan',
        'deskripsi' => 'Laporan kehadiran per periode dan unit kerja beserta ekspor. Dikerjakan pada Sesi S22.',
    ])->name('laporan.index');
});
