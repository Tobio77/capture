<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kode per event ditiadakan; penggantinya menempel pada unit kerja.
 *
 * Lihat catatan pada migration `tambah_kode_perangkat_ke_unit_kerja`. Isi
 * tabel ini tidak dipindahkan ke mana pun dan memang tidak perlu: sebuah kode
 * event hanya berarti selama eventnya menerima tap, dan seluruh event yang
 * pernah memakainya sudah tercatat keanggotaan perangkatnya pada
 * `event_kiosk` — di sanalah jawaban "perangkat mana yang melayani kegiatan
 * ini" tersimpan, bukan di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('event_kode_unit');
    }

    public function down(): void
    {
        Schema::create('event_kode_unit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_absen_id')->constrained('event_absen')->cascadeOnDelete();
            $table->foreignId('unit_kerja_id')->constrained('unit_kerja')->cascadeOnDelete();
            $table->string('kode', 8)->unique();
            $table->foreignId('direset_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('direset_pada')->nullable();
            $table->timestamps();

            $table->unique(['event_absen_id', 'unit_kerja_id']);
        });
    }
};
