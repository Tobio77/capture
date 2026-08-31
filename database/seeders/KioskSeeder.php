<?php

namespace Database\Seeders;

use App\Models\Kiosk;
use App\Models\UnitKerja;
use App\Services\KioskService;
use Illuminate\Database\Seeder;

/**
 * Titik absen contoh untuk pengembangan lokal.
 * Kiosk didaftarkan admin (FR-USR-02); perangkatnya diaktifkan sendiri
 * dengan menukarkan kode aktivasi di layar /kiosk/aktivasi (S04).
 */
class KioskSeeder extends Seeder
{
    public function run(KioskService $kioskService): void
    {
        $daftar = [
            ['nama_titik' => 'Aula Senam BLK Surabaya', 'unit' => 'BLK-SBY'],
            ['nama_titik' => 'Lobi Utama BLK Surabaya', 'unit' => 'BLK-SBY'],
            ['nama_titik' => 'Ruang Rapat Kantor Dinas', 'unit' => 'DISNAKER'],
            ['nama_titik' => 'Workshop BLK Mojokerto', 'unit' => 'BLK-MJK'],
        ];

        $unitKerja = UnitKerja::pluck('id', 'kode');
        $kode = [];

        foreach ($daftar as $titik) {
            $kiosk = Kiosk::firstOrCreate(
                ['nama_titik' => $titik['nama_titik']],
                ['unit_kerja_id' => $unitKerja[$titik['unit']], 'aktif' => true],
            );

            if (! $kiosk->sudah_diaktifkan) {
                $kode[$kiosk->nama_titik] = $kioskService->terbitkanKodeAktivasi($kiosk);
            }
        }

        if ($kode !== [] && $this->command) {
            $this->command->newLine();
            $this->command->info('Kode aktivasi kiosk (berlaku '.KioskService::MASA_KODE_JAM.' jam):');
            foreach ($kode as $namaTitik => $nilai) {
                $this->command->line(sprintf('  %-28s %s', $namaTitik, $nilai));
            }
        }
    }
}
