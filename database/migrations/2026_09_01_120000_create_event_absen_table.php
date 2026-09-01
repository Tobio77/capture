<?php

use App\Enums\CakupanEvent;
use App\Enums\StatusEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event absensi beserta cakupan unit kerjanya (FR-EVT-01, FR-EVT-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_absen', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->date('tanggal');
            $table->time('jam_mulai');

            // Disalin dari Setting Absen saat event dibuat, lalu berdiri
            // sendiri — mengubah setting global tidak menggeser event lama
            // (FR-SET-02).
            $table->unsignedSmallInteger('toleransi_menit');

            $table->enum('cakupan', CakupanEvent::nilai())->default(CakupanEvent::Unit->value);
            $table->enum('status', StatusEvent::nilai())->default(StatusEvent::Aktif->value);

            // Pembuat dipertahankan walau akunnya dihapus, agar jejak
            // pertanggungjawaban event tidak hilang.
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('ditutup_pada')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index(['tanggal', 'status']);
            $table->index('status');
        });

        Schema::create('event_unit_kerja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_absen_id')->constrained('event_absen')->cascadeOnDelete();
            $table->foreignId('unit_kerja_id')->constrained('unit_kerja')->cascadeOnDelete();

            $table->unique(['event_absen_id', 'unit_kerja_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_unit_kerja');
        Schema::dropIfExists('event_absen');
    }
};
