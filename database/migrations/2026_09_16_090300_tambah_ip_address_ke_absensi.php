<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alamat IP perangkat yang melayani sebuah tap.
 *
 * Jumlah perangkat absen per unit kerja tidak dibatasi: sebuah UPT boleh
 * memakai tiga komputer hari Rabu dan empat hari Kamis, dan itu memang
 * perilaku yang dikehendaki. Konsekuensinya rekap harus dapat menjawab dari
 * MESIN MANA sebuah kehadiran masuk — bukan sekadar dari unit mana.
 *
 * Alamatnya disimpan pada barisnya sendiri, bukan dibaca ulang dari
 * `kiosk.ip_terakhir` saat rekap dirakit. Kolom itu bergerak: satu perangkat
 * berpindah jaringan, dipindahkan ke ruangan lain, atau menerima alamat DHCP
 * yang berbeda keesokan harinya — dan rekap bulan lalu akan ikut berubah
 * mengikuti keadaan hari ini. Sejalan dengan `status_ketepatan` dan
 * `hari_libur`, yang juga ditetapkan saat tap dan tidak pernah diturunkan
 * ulang.
 *
 * Null pada baris lama, dan pada tap yang datang dari layar absen di peramban
 * admin sebelum kolom ini ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absensi', function (Blueprint $table) {
            // 45 karakter menampung IPv6 beserta notasi IPv4-mapped, mengikuti
            // ukuran yang sama pada `kiosk.ip_terakhir` dan `event_kiosk`.
            $table->string('ip_address', 45)->nullable()->after('kiosk_id');
        });
    }

    public function down(): void
    {
        Schema::table('absensi', function (Blueprint $table) {
            $table->dropColumn('ip_address');
        });
    }
};
