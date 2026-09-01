<?php

use App\Models\UnitKerja;
use Illuminate\Database\Migrations\Migration;

/**
 * `DISNAKER` adalah unit lokal SI-ABSEN (tempat kepala dinas, tempat bernaung
 * akun Admin Dinas dan kiosk kantor dinas) yang tidak dikirim WORKA, sehingga
 * sinkronisasi tidak pernah mengisi induknya. Tautannya diset sekali di sini
 * agar pohon unit kerja tetap berakar tunggal pada `PROV-JATIM`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $lokal = UnitKerja::query()->where('kode', 'DISNAKER')->first();
        $induk = UnitKerja::query()->where('kode', 'DISNAKERTRANS')->first();

        // Pada instalasi baru, DISNAKERTRANS baru ada setelah sinkronisasi
        // WORKA pertama; migration dibiarkan tidak berbuat apa-apa.
        if ($lokal === null || $induk === null || $lokal->induk_id !== null) {
            return;
        }

        $lokal->induk_id = $induk->id;
        $lokal->save();
    }

    public function down(): void
    {
        UnitKerja::query()
            ->where('kode', 'DISNAKER')
            ->update(['induk_id' => null]);
    }
};
