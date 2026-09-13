<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kode aktivasi perangkat disimpan sebagai hash (perbaikan L-1, audit pra-deploy).
 *
 * Sebelumnya kolom ini menyimpan kodenya apa adanya. Risikonya memang kecil —
 * sekali pakai, berlaku 24 jam, dan bertanda `#[Hidden]` sehingga tidak pernah
 * ikut terserialisasi — tetapi siapa pun yang dapat membaca tabel `kiosk`
 * dapat mengaktifkan perangkat atas nama titik absen mana pun, dan perangkat
 * yang lahir dari situ terlihat sah di Daftar Perangkat.
 *
 * BERBEDA dari kode gabung event, yang sengaja TIDAK di-hash: kode itu harus
 * dapat dibaca ulang admin untuk dibacakan kepada petugas di ruangan lain
 * (lihat 2026_09_05_110000_create_event_kode_unit_table.php). Kode aktivasi
 * tidak punya kebutuhan itu — ia ditampilkan SEKALI lewat flash saat
 * diterbitkan, dan daftar perangkat hanya menyatakan masih berlaku atau tidak.
 *
 * Kolomnya dilebarkan dari 20 menjadi 64 karakter untuk menampung SHA-256
 * heksadesimal.
 *
 * Kode yang masih beredar saat migrasi ini berjalan DIHANGUSKAN: nilai lama
 * berbentuk kode mentah tidak akan pernah cocok dengan pencarian berbasis
 * hash, dan membiarkannya hanya melahirkan kegagalan aktivasi yang tidak dapat
 * dijelaskan kepada petugas. Admin menerbitkan ulang dari Kelola Perangkat
 * Absen — satu klik per perangkat.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('kiosk')
            ->whereNotNull('kode_aktivasi')
            ->update(['kode_aktivasi' => null, 'kode_aktivasi_kedaluwarsa_at' => null]);

        Schema::table('kiosk', function (Blueprint $table) {
            $table->string('kode_aktivasi', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Hash tidak dapat dikembalikan menjadi kodenya; yang tersisa
        // dikosongkan supaya kolom sempit tidak menolak nilai yang ada.
        DB::table('kiosk')
            ->whereNotNull('kode_aktivasi')
            ->update(['kode_aktivasi' => null, 'kode_aktivasi_kedaluwarsa_at' => null]);

        Schema::table('kiosk', function (Blueprint $table) {
            $table->string('kode_aktivasi', 20)->nullable()->change();
        });
    }
};
