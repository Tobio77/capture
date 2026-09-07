<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kalender hari kerja dan hari libur (FR-SET-08).
 *
 * Hari libur MENANDAI absensi, tidak menutupnya. Keputusan pemilik sistem, dan
 * alasannya kuat: kantor dinas menjalankan piket akhir pekan, dan menutup
 * absen umum berarti petugas piket yang benar-benar masuk tidak dapat mencatat
 * kehadirannya sama sekali tanpa admin membuka paksa dari rumah.
 *
 * Tiga perubahan:
 *
 * 1. `unit_kerja.hari_kerja` — hari kerja PER UNIT, bukan satu aturan global.
 *    UPT BLK menyelenggarakan pelatihan yang kerap berjalan Sabtu, sementara
 *    kantor dinas tidak; aturan global akan salah untuk salah satunya. Null
 *    berarti mewarisi induk, dan akhirnya Senin–Jumat.
 *
 * 2. Tabel `hari_libur` — tanggal tertentu yang bukan hari kerja.
 *    `unit_kerja_id` null berarti berlaku bagi semua unit (libur nasional),
 *    diisi berarti khusus unit itu (mis. HUT UPT). Diisi admin, tidak ditarik
 *    dari layanan luar: jaringan dinas kerap berada di belakang proxy yang
 *    menyaring keluar, dan kalender yang gagal diam-diam lebih buruk daripada
 *    kalender yang diisi tangan.
 *
 * 3. `absensi.hari_libur` — penanda yang DISIMPAN, bukan dihitung ulang saat
 *    dibaca. Kalender dapat berubah kemudian; absensi adalah catatan
 *    administratif, dan catatan yang berubah arti setiap kali admin menambah
 *    tanggal libur tidak dapat dipertanggungjawabkan. Sejalan dengan
 *    `status_ketepatan`, yang juga disimpan saat tap, bukan diturunkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_kerja', function (Blueprint $table) {
            /*
             * Larik nomor hari ISO-8601: 1 Senin sampai 7 Minggu. Null berarti
             * belum diatur — pembacanya menelusuri induk, lalu jatuh ke
             * Senin–Jumat.
             */
            $table->json('hari_kerja')->nullable()->after('aktif');
        });

        Schema::create('hari_libur', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal');
            $table->string('keterangan', 150);

            // Null = seluruh unit kerja (libur nasional).
            $table->foreignId('unit_kerja_id')->nullable()->constrained('unit_kerja')->cascadeOnDelete();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * Satu tanggal hanya boleh sekali per cakupan. Tanpa ini, dua admin
             * yang menambahkan "Idulfitri" pada hari yang sama menghasilkan dua
             * baris, dan daftar liburnya berisi kembar yang membingungkan.
             */
            $table->unique(['tanggal', 'unit_kerja_id']);
            $table->index('tanggal');
        });

        Schema::table('absensi', function (Blueprint $table) {
            $table->boolean('hari_libur')->default(false)->after('status_ketepatan');
            $table->index('hari_libur');
        });
    }

    public function down(): void
    {
        Schema::table('absensi', function (Blueprint $table) {
            $table->dropIndex(['hari_libur']);
            $table->dropColumn('hari_libur');
        });

        Schema::dropIfExists('hari_libur');

        Schema::table('unit_kerja', function (Blueprint $table) {
            $table->dropColumn('hari_kerja');
        });
    }
};
