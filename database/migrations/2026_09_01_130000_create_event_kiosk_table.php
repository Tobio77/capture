<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pencatatan kiosk yang aktif pada sebuah event (FR-EVT-03).
 *
 * Satu baris per pasangan event x kiosk, bukan satu baris per kunjungan:
 * yang dibutuhkan layar detail event adalah daftar kiosk terhubung, bukan
 * riwayat setiap kali kiosk menyentuh event. `aktif_pada` menahan waktu
 * pertama kali kiosk melayani event ini, `terakhir_aktif_pada` bergerak
 * mengikuti aktivitas terbaru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_kiosk', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_absen_id')->constrained('event_absen')->cascadeOnDelete();
            $table->foreignId('kiosk_id')->constrained('kiosk')->cascadeOnDelete();

            // 45 karakter menampung IPv6 beserta notasi IPv4-mapped.
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('aktif_pada');
            $table->timestamp('terakhir_aktif_pada');

            $table->unique(['event_absen_id', 'kiosk_id']);
            $table->index('terakhir_aktif_pada');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_kiosk');
    }
};
