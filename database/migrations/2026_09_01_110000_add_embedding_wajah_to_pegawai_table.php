<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penyimpanan wajah referensi (FR-PEG-05).
 *
 * Kiosk mengambil *embedding*, bukan foto (SDD §3 modul verifikasi wajah),
 * sehingga foto referensi tidak pernah meninggalkan server dan hanya deskriptor
 * 128 dimensi yang dikirim ke jaringan lokal. Embedding dihitung di browser
 * saat pendaftaran — tidak ada pemrosesan wajah di sisi server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pegawai', function (Blueprint $table) {
            $table->json('embedding_wajah')->nullable()->after('foto_referensi_path');
            $table->timestamp('wajah_didaftarkan_at')->nullable()->after('wajah_terdaftar');
        });
    }

    public function down(): void
    {
        Schema::table('pegawai', function (Blueprint $table) {
            $table->dropColumn(['embedding_wajah', 'wajah_didaftarkan_at']);
        });
    }
};
