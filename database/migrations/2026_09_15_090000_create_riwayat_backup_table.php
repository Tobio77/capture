<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('riwayat_backup', function (Blueprint $table) {
            $table->id();

            // 'manual' (tombol admin) atau 'terjadwal' (Schedule::command,
            // lihat routes/console.php) — dua-duanya sama pentingnya,
            // ditampilkan pada Riwayat supaya admin tahu yang mana asalnya.
            $table->string('dipicu_oleh', 16);

            // Null untuk backup terjadwal — tidak ada admin yang memintanya.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 16)->default('antre');
            $table->string('nama_berkas');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('ukuran_bytes')->nullable();
            $table->text('pesan_galat')->nullable();
            $table->timestamp('selesai_pada')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('riwayat_backup');
    }
};
