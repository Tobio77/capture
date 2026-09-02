<?php

use App\Enums\SumberKiosk;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menandai asal-usul sebuah perangkat absen.
 *
 * Ketika "Wajib Kode Aktivasi" dimatikan (FR-SET-06), perangkat yang membuka
 * layar absen tanpa kode dibuatkan entrinya sendiri. Entri semacam itu tidak
 * pernah ditinjau admin, sehingga harus dapat dibedakan dari perangkat yang
 * memang didaftarkan — pada Daftar Perangkat maupun pada rekap per event,
 * di mana pertanyaannya kelak menjadi "absen ini datang dari mesin siapa".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosk', function (Blueprint $table) {
            $table->enum('sumber', SumberKiosk::nilai())
                ->default(SumberKiosk::Terdaftar->value)
                ->after('nama_titik');
        });
    }

    public function down(): void
    {
        Schema::table('kiosk', function (Blueprint $table) {
            $table->dropColumn('sumber');
        });
    }
};
