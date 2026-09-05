<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Override buka/tutup Absen Umum, disimpan pada sesi hariannya (FR-SET-07).
 *
 * Absen Umum kini punya jendela jam bawaan — datang dan pulang terpisah — dan
 * admin membutuhkan jalan keluar untuk kasus khusus: apel dadakan sore hari,
 * atau justru menutup lebih awal ketika kegiatan dipindah. Override itulah
 * yang menjadi jalan keluarnya, dan ia selalu menang atas jadwal.
 *
 * **Disimpan pada sesi harian, bukan pada tabel pengaturan.** Sesi absen umum
 * sudah berupa satu baris per unit kerja per tanggal (`event_absen` berjenis
 * `umum` dengan `kunci_sesi`), sehingga menautkan override ke sana membuat
 * syarat "tidak boleh terbawa ke hari berikutnya" terpenuhi oleh strukturnya
 * sendiri: besok adalah baris yang berbeda, dan barisnya lahir tanpa override.
 * Tidak ada tugas terjadwal yang perlu membersihkannya, dan tidak ada keadaan
 * yang bisa tertinggal karena penjadwal mati semalam.
 *
 * Kolomnya null untuk event kegiatan — kegiatan tidak mengenal jendela jam;
 * yang membuka dan menutupnya adalah status entry (FR-EVT-04).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_absen', function (Blueprint $table) {
            // 'buka' atau 'tutup'; null berarti mengikuti jadwal.
            $table->string('override_absen', 10)->nullable()->after('kunci_sesi');

            // Siapa dan kapan — supaya admin yang membukanya besok pagi tahu
            // keadaan itu datang dari seseorang, bukan dari jadwal.
            $table->foreignId('override_oleh')->nullable()->after('override_absen')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('override_pada')->nullable()->after('override_oleh');
        });
    }

    public function down(): void
    {
        Schema::table('event_absen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('override_oleh');
            $table->dropColumn(['override_absen', 'override_pada']);
        });
    }
};
