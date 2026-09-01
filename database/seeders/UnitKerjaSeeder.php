<?php

namespace Database\Seeders;

use App\Models\UnitKerja;
use Illuminate\Database\Seeder;

/**
 * Data contoh unit kerja untuk pengembangan lokal.
 * Daftar final unit kerja ditetapkan admin melalui menu Setting Unit Kerja (FR-UNIT-01).
 */
class UnitKerjaSeeder extends Seeder
{
    public function run(): void
    {
        $daftar = [
            ['kode' => 'DISNAKER', 'nama' => 'Dinas Tenaga Kerja dan Transmigrasi Provinsi Jawa Timur'],
            ['kode' => 'BLK-SBY', 'nama' => 'UPT Balai Latihan Kerja Surabaya'],
            ['kode' => 'BLK-MJK', 'nama' => 'UPT Balai Latihan Kerja Mojokerto'],
            ['kode' => 'BLK-JBR', 'nama' => 'UPT Balai Latihan Kerja Jember'],
            ['kode' => 'UPT-K3', 'nama' => 'UPT Keselamatan dan Kesehatan Kerja'],
        ];

        foreach ($daftar as $unit) {
            UnitKerja::updateOrCreate(
                ['kode' => $unit['kode']],
                ['nama' => $unit['nama'], 'aktif' => true],
            );
        }

        $this->tautkanDisnakerKeIndukWorka();
    }

    /**
     * `DISNAKER` adalah unit lokal (tempat kepala dinas) yang tidak dikirim
     * WORKA, jadi sinkronisasi tidak pernah mengisi induknya. Tautannya diurus
     * di sini — dilewati bila WORKA belum pernah disinkronkan, karena unit
     * `DISNAKERTRANS` baru muncul setelah `pegawai:sinkron` pertama.
     */
    protected function tautkanDisnakerKeIndukWorka(): void
    {
        $induk = UnitKerja::query()->where('kode', 'DISNAKERTRANS')->first();

        if ($induk === null) {
            return;
        }

        UnitKerja::query()
            ->where('kode', 'DISNAKER')
            ->whereNull('induk_id')
            ->update(['induk_id' => $induk->id]);
    }
}
