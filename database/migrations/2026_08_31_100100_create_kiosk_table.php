<?php

use App\Enums\StatusKiosk;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosk', function (Blueprint $table) {
            $table->id();
            $table->string('nama_titik', 150);
            $table->foreignId('unit_kerja_id')->constrained('unit_kerja')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('device_token', 100)->unique();
            $table->string('ip_terakhir', 45)->nullable();
            $table->enum('status', StatusKiosk::nilai())->default(StatusKiosk::Offline->value);
            $table->timestamp('login_terakhir_at')->nullable();
            $table->boolean('aktif')->default(true);
            $table->timestamps();

            $table->index(['unit_kerja_id', 'aktif']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk');
    }
};
