<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penyimpanan kunci–nilai untuk pengaturan yang boleh diubah admin saat
     * aplikasi berjalan (mis. kredensial WORKA dan status sinkronisasi),
     * sehingga tidak perlu menyunting .env di server.
     */
    public function up(): void
    {
        Schema::create('pengaturan', function (Blueprint $table) {
            $table->id();
            $table->string('kunci', 100)->unique();
            $table->text('nilai')->nullable();

            // Nilai sensitif (token API) disimpan terenkripsi.
            $table->boolean('terenkripsi')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan');
    }
};
