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

        // Induk unit lokal (mis. DISNAKER) sengaja tidak diurus di sini:
        // `pegawai:sinkron` menegakkannya sendiri lewat peta
        // `services.worka.induk_unit_lokal`, sehingga hasil akhirnya tidak
        // bergantung pada urutan seeding terhadap sinkronisasi.
    }
}
