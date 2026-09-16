<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Buang kunci pengaturan Mode Terbuka yang maknanya sudah terbalik.
 *
 * `absen.wajib_kode_aktivasi` bernilai "1" berarti perangkat WAJIB memakai
 * kode aktivasi. Penggantinya, `absen.pendaftaran_perangkat_aktif`, menyatakan
 * hal yang sama dengan arah terbalik — dan bawaannya kini MATI, sebab jalur
 * masuk yang berlaku sehari-hari adalah kode unit kerja.
 *
 * Barisnya dibuang alih-alih diterjemahkan. Menerjemahkannya berarti setiap
 * instalasi yang berjalan menyala dengan Mode Pendaftaran Perangkat AKTIF —
 * nilai lamanya hampir selalu "1", karena itulah bawaannya dulu — sehingga
 * tidak satu pun perangkat dapat masuk memakai kode unit kerja pada hari
 * rilis, dan tidak ada yang tahu mengapa. Yang dikehendaki justru sebaliknya:
 * seluruh instalasi mulai dari bawaan baru, dan admin yang memang menginginkan
 * jalur lama menyalakannya sendiri di Setting Absen.
 *
 * `absen.mode_terbuka_sejak` ikut dibuang: ia stempel waktu bagi peringatan
 * yang sudah tidak ada lagi (lihat SettingAbsenService::catatPelonggaran()).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pengaturan')
            ->whereIn('kunci', ['absen.wajib_kode_aktivasi', 'absen.mode_terbuka_sejak'])
            ->delete();
    }

    /**
     * Tidak ada yang dapat dipulihkan: nilai lamanya sudah terbuang, dan
     * menebaknya kembali justru melahirkan keadaan yang tidak pernah ada.
     * Baris ini akan lahir sendiri dengan bawaannya bila kode lama dipasang
     * kembali.
     */
    public function down(): void
    {
        // Sengaja tidak melakukan apa pun; lihat catatan di atas.
    }
};
