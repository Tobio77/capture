<?php

use App\Enums\CakupanEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membuka jalan bagi cakupan event baru tanpa ALTER enum setiap kali.
 *
 * Cakupan bertambah seiring kebutuhan lapangan — "Wilayah Kerja Surabaya"
 * adalah yang ketiga — dan menyimpannya sebagai enum basis data berarti setiap
 * penambahan menuntut migration ALTER yang berbeda perlakuan antara MySQL
 * (produksi) dan SQLite (pengujian). Mengikuti preseden `log_aktivitas.aksi`:
 * kolomnya cukup string, dan {@see CakupanEvent} yang menjaga daftar nilainya —
 * ditegakkan oleh cast Eloquent serta Rule::enum pada SimpanEventRequest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_absen', function (Blueprint $table) {
            $table->string('cakupan', 30)->default(CakupanEvent::Unit->value)->change();
        });
    }

    public function down(): void
    {
        Schema::table('event_absen', function (Blueprint $table) {
            $table->enum('cakupan', ['unit', 'semua_unit'])
                ->default(CakupanEvent::Unit->value)
                ->change();
        });
    }
};
