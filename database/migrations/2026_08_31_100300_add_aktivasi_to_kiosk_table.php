<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosk', function (Blueprint $table) {
            // Kiosk yang baru didaftarkan admin belum memegang token apa pun.
            $table->string('device_token', 100)->nullable()->change();

            // Kode sekali pakai yang diserahkan admin kepada petugas lokasi.
            $table->string('kode_aktivasi', 20)->nullable()->unique()->after('device_token');
            $table->timestamp('kode_aktivasi_kedaluwarsa_at')->nullable()->after('kode_aktivasi');
            $table->timestamp('diaktifkan_pada')->nullable()->after('login_terakhir_at');
        });
    }

    public function down(): void
    {
        Schema::table('kiosk', function (Blueprint $table) {
            $table->dropUnique(['kode_aktivasi']);
            $table->dropColumn(['kode_aktivasi', 'kode_aktivasi_kedaluwarsa_at', 'diaktifkan_pada']);
            $table->string('device_token', 100)->nullable(false)->change();
        });
    }
};
