<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pegawai', function (Blueprint $table) {
            $table->id();
            $table->string('nip', 20)->unique();
            $table->string('nama', 150);
            $table->foreignId('unit_kerja_id')->constrained('unit_kerja')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('jabatan', 150)->nullable();
            $table->string('foto_referensi_path', 255)->nullable();
            $table->boolean('wajah_terdaftar')->default(false);
            $table->timestamp('sumber_sinkron_terakhir')->nullable();
            $table->timestamps();

            // FR-PEG-03 (filter status wajah) & FR-PEG-04 (pencarian nama/NIP)
            $table->index('nama');
            $table->index('wajah_terdaftar');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pegawai');
    }
};
