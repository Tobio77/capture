<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat Generate Laporan Resmi (FR-LAP-04, revisi antrian).
 *
 * Setiap permintaan Generate Laporan mencatat satu baris di sini SEBELUM
 * berkasnya dirakit — statusnya berpindah antre → diproses → selesai/gagal
 * seiring job berjalan (lihat App\Jobs\BuatLaporanResmiJob). Baris yang
 * gagal tetap disimpan, bukan dihapus: admin perlu tahu APA yang gagal,
 * bukan cuma bahwa sesuatu pernah diminta lalu lenyap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('riwayat_laporan', function (Blueprint $table) {
            $table->id();

            // Cascade: riwayat generate laporan bukan audit trail permanen
            // (itu tugas LogAktivitas) — bila akunnya dihapus, riwayat
            // pribadinya ikut pergi.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('format', 8);
            $table->date('dari');
            $table->date('sampai');
            $table->foreignId('unit_kerja_id')->nullable()->constrained('unit_kerja')->nullOnDelete();

            $table->string('status', 16)->default('antre');
            $table->string('nama_berkas');

            // Kosong sampai statusnya selesai; path relatif terhadap disk
            // 'local' (storage/app/private), tidak pernah disk publik.
            $table->string('path')->nullable();

            $table->text('pesan_galat')->nullable();
            $table->timestamp('selesai_pada')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('riwayat_laporan');
    }
};
