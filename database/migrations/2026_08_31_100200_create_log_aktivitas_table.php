<?php

use App\Enums\AksiLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_aktivitas', function (Blueprint $table) {
            $table->id();

            // Pelaku: akun admin, perangkat kiosk, atau tidak dikenal (percobaan masuk gagal).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('kiosk_id')->nullable()->constrained('kiosk')->nullOnDelete();

            $table->enum('aksi', AksiLog::nilai());
            $table->string('deskripsi', 255);

            // Subjek opsional untuk mencatat perubahan data penting.
            $table->nullableMorphs('subjek');

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            // Log bersifat append-only; tidak ada updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['aksi', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_aktivitas');
    }
};
