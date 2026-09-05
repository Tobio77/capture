<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `event_kiosk` berubah makna: dari catatan "perangkat ini pernah melayani
 * event" menjadi DAFTAR KEANGGOTAAN yang menentukan boleh-tidaknya sebuah
 * perangkat membuka layar Absen Event.
 *
 * Kolom baru menyimpan lewat kode unit mana perangkat bergabung, sehingga
 * layar detail event dapat memisahkan perangkat per unit kerja pada kegiatan
 * lintas unit — dan `bergabung_pada` menahan waktu penggabungan TERAKHIR,
 * berbeda dari `aktif_pada` yang menahan yang pertama.
 *
 * Baris lama diberi `bergabung_pada` = `aktif_pada`: perangkat yang sudah
 * terhubung sebelum kode diperkenalkan tetap dianggap anggota, supaya event
 * yang sedang berjalan saat rilis tidak mendadak kehilangan titik absennya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_kiosk', function (Blueprint $table) {
            $table->foreignId('unit_kerja_id')
                ->nullable()
                ->after('kiosk_id')
                ->constrained('unit_kerja')
                ->nullOnDelete();

            $table->timestamp('bergabung_pada')->nullable()->after('ip_address');
        });

        // Kolom baru diisi dari yang sudah ada, bukan dibiarkan null: null di
        // sini berarti "belum pernah bergabung", dan itu keliru bagi baris
        // yang justru menjadi bukti perangkat pernah melayani event.
        Schema::getConnection()
            ->table('event_kiosk')
            ->update(['bergabung_pada' => Schema::getConnection()->raw('aktif_pada')]);
    }

    public function down(): void
    {
        Schema::table('event_kiosk', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_kerja_id');
            $table->dropColumn('bergabung_pada');
        });
    }
};
