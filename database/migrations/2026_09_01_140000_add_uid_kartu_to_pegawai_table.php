<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemetaan kartu RFID ke pegawai (FR-TAP-03).
 *
 * Reader 13,56 MHz kelas USB/HID yang dipakai di lokasi mengeluarkan UID
 * kartu, bukan NIP, sehingga tap perlu ditautkan ke pegawai lewat kolom ini.
 * Nullable karena tidak setiap pegawai memegang kartu: absen manual tetap
 * tersedia sebagai jalur cadangan.
 *
 * Milik SI-ABSEN sendiri — sinkronisasi WORKA tidak pernah menimpanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pegawai', function (Blueprint $table) {
            $table->string('uid_kartu', 32)->nullable()->unique()->after('nip');
            $table->timestamp('kartu_didaftarkan_at')->nullable()->after('uid_kartu');
        });
    }

    public function down(): void
    {
        Schema::table('pegawai', function (Blueprint $table) {
            $table->dropUnique(['uid_kartu']);
            $table->dropColumn(['uid_kartu', 'kartu_didaftarkan_at']);
        });
    }
};
