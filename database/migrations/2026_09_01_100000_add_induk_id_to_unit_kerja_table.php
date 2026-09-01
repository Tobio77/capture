<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hirarki unit kerja mengikuti WORKA: satu unit dapat bernaung di bawah
     * unit lain (mis. seksi di bawah UPT, UPT di bawah dinas). Nullable karena
     * unit puncak (Pemerintah Provinsi Jawa Timur) tidak memiliki induk, dan
     * karena unit yang dibuat manual oleh admin belum tentu punya induk.
     */
    public function up(): void
    {
        Schema::table('unit_kerja', function (Blueprint $table) {
            $table->foreignId('induk_id')
                ->nullable()
                ->after('nama')
                ->constrained('unit_kerja')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('unit_kerja', function (Blueprint $table) {
            $table->dropForeign(['induk_id']);
            $table->dropColumn('induk_id');
        });
    }
};
