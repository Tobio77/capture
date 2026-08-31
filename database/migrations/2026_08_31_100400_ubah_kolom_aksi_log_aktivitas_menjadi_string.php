<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daftar aksi audit trail bertambah seiring modul baru (aktivasi kiosk, tap absen,
     * dan seterusnya). Menyimpannya sebagai enum di database berarti setiap penambahan
     * aksi membutuhkan migration ALTER; cukup simpan sebagai string dan biarkan
     * App\Enums\AksiLog yang menjaga daftar nilainya.
     */
    public function up(): void
    {
        Schema::table('log_aktivitas', function (Blueprint $table) {
            $table->string('aksi', 50)->change();
        });
    }

    public function down(): void
    {
        Schema::table('log_aktivitas', function (Blueprint $table) {
            $table->enum('aksi', ['masuk', 'masuk_gagal', 'keluar', 'buat', 'ubah', 'hapus'])->change();
        });
    }
};
