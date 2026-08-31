<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dua medan yang bersumber dari WORKA:
     *
     * - `aktif` mencerminkan status_aktif pegawai. Pegawai yang pensiun atau
     *   pindah tetap disimpan (riwayat absensinya menautnya), tetapi tidak
     *   boleh lagi diabsen di kiosk.
     * - `foto_tersedia_worka` menandai WORKA punya foto referensi untuk NIP
     *   ini. Berbeda dari `wajah_terdaftar` yang merupakan penanda milik
     *   SI-ABSEN sendiri (S08); WORKA tidak pernah mengirimkan jalur berkasnya.
     */
    public function up(): void
    {
        Schema::table('pegawai', function (Blueprint $table) {
            $table->boolean('aktif')->default(true)->after('jabatan');
            $table->boolean('foto_tersedia_worka')->default(false)->after('foto_referensi_path');
            $table->index(['unit_kerja_id', 'aktif']);
        });
    }

    public function down(): void
    {
        Schema::table('pegawai', function (Blueprint $table) {
            $table->dropIndex(['unit_kerja_id', 'aktif']);
            $table->dropColumn(['aktif', 'foto_tersedia_worka']);
        });
    }
};
