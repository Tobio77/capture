<?php

use App\Enums\JenisAbsen;
use App\Enums\MetodeAbsen;
use App\Enums\StatusKetepatan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan kehadiran (FR-TAP-05, FR-TAP-07).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absensi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_absen_id')->constrained('event_absen')->cascadeOnDelete();
            $table->foreignId('pegawai_id')->constrained('pegawai')->cascadeOnDelete();

            // Kiosk boleh dilepas tanpa menghapus riwayat absensinya.
            $table->foreignId('kiosk_id')->nullable()->constrained('kiosk')->nullOnDelete();

            $table->enum('jenis', JenisAbsen::nilai());
            $table->enum('metode', MetodeAbsen::nilai());
            $table->dateTime('waktu');

            // Hanya terisi untuk jenis Datang; Pulang tidak mengenal terlambat.
            $table->enum('status_ketepatan', StatusKetepatan::nilai())->nullable();

            // Null bila admin mematikan verifikasi wajah pada Setting Absen.
            $table->decimal('skor_kecocokan_wajah', 5, 2)->nullable();

            $table->string('foto_path', 255)->nullable();
            $table->timestamps();

            /*
             * FR-TAP-05: tap berulang untuk jenis yang sama memperbarui baris
             * yang ada, bukan menduplikasinya. Keunikan ditegakkan di basis
             * data agar dua kiosk yang men-tap bersamaan tidak menyelinap.
             */
            $table->unique(['event_absen_id', 'pegawai_id', 'jenis']);

            $table->index(['event_absen_id', 'waktu']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absensi');
    }
};
