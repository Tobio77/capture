<?php

use App\Enums\JenisEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memisahkan event kegiatan dari sesi absen umum harian.
 *
 * Sesi umum dibuka sistem sendiri saat tidak ada kegiatan yang berjalan,
 * satu baris per unit kerja per tanggal. Ia menumpang seluruh mesin yang
 * sudah ada — absensi, rekap, laporan, verifikasi wajah — sehingga tidak
 * ada tabel baru; yang dibutuhkan hanya penanda jenis agar keduanya dapat
 * dibedakan pada daftar event dan pada pemeriksaan tumpang tindih
 * (FR-EVT-06), yang tidak berlaku bagi sesi umum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_absen', function (Blueprint $table) {
            $table->enum('jenis', JenisEvent::nilai())
                ->default(JenisEvent::Kegiatan->value)
                ->after('nama');

            // Pencarian sesi umum hari ini terjadi pada tiap tap; indeks ini
            // yang menjawabnya tanpa memindai seluruh riwayat event.
            $table->index(['jenis', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::table('event_absen', function (Blueprint $table) {
            $table->dropIndex(['jenis', 'tanggal']);
            $table->dropColumn('jenis');
        });
    }
};
