<?php

namespace Database\Seeders;

use App\Enums\StatusKiosk;
use App\Models\Kiosk;
use App\Models\UnitKerja;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Data contoh perangkat kiosk untuk pengembangan lokal.
 * device_token asli diterbitkan melalui alur aktivasi perangkat (S04).
 */
class KioskSeeder extends Seeder
{
    public function run(): void
    {
        $daftar = [
            ['nama_titik' => 'Aula Senam BLK Surabaya', 'unit' => 'BLK-SBY'],
            ['nama_titik' => 'Lobi Utama BLK Surabaya', 'unit' => 'BLK-SBY'],
            ['nama_titik' => 'Ruang Rapat Kantor Dinas', 'unit' => 'DISNAKER'],
            ['nama_titik' => 'Workshop BLK Mojokerto', 'unit' => 'BLK-MJK'],
        ];

        $unitKerja = UnitKerja::pluck('id', 'kode');

        foreach ($daftar as $kiosk) {
            Kiosk::firstOrCreate(
                ['nama_titik' => $kiosk['nama_titik']],
                [
                    'unit_kerja_id' => $unitKerja[$kiosk['unit']],
                    'device_token' => Str::random(64),
                    'status' => StatusKiosk::Offline,
                    'aktif' => true,
                ],
            );
        }
    }
}
