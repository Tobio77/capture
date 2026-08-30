<?php

namespace Database\Seeders;

use App\Models\Pegawai;
use App\Models\UnitKerja;
use Illuminate\Database\Seeder;

/**
 * Data contoh pegawai untuk pengembangan lokal.
 * Pada operasional, data pegawai berasal dari sinkronisasi WORKA/BKD (FR-PEG-01).
 */
class PegawaiSeeder extends Seeder
{
    public function run(): void
    {
        $daftar = [
            ['nip' => '198503122010011002', 'nama' => 'Bambang Setiawan', 'unit' => 'DISNAKER', 'jabatan' => 'Analis Ketenagakerjaan', 'wajah' => true],
            ['nip' => '198711052011012003', 'nama' => 'Siti Rahayu', 'unit' => 'DISNAKER', 'jabatan' => 'Pengantar Kerja', 'wajah' => true],
            ['nip' => '199002142014031004', 'nama' => 'Ahmad Fauzi', 'unit' => 'BLK-SBY', 'jabatan' => 'Instruktur Pelatihan', 'wajah' => true],
            ['nip' => '199206302015022005', 'nama' => 'Dewi Anggraini', 'unit' => 'BLK-SBY', 'jabatan' => 'Pengadministrasi Umum', 'wajah' => false],
            ['nip' => '198809172012011006', 'nama' => 'Hendra Kusuma', 'unit' => 'BLK-SBY', 'jabatan' => 'Instruktur Pelatihan', 'wajah' => false],
            ['nip' => '199401222016032007', 'nama' => 'Rina Puspitasari', 'unit' => 'BLK-MJK', 'jabatan' => 'Pranata Komputer', 'wajah' => true],
            ['nip' => '198612082010012008', 'nama' => 'Joko Prasetyo', 'unit' => 'BLK-JBR', 'jabatan' => 'Instruktur Pelatihan', 'wajah' => false],
            ['nip' => '199508112017011009', 'nama' => 'Maya Kartika', 'unit' => 'UPT-K3', 'jabatan' => 'Analis Keselamatan Kerja', 'wajah' => false],
        ];

        $unitKerja = UnitKerja::pluck('id', 'kode');

        foreach ($daftar as $pegawai) {
            Pegawai::updateOrCreate(
                ['nip' => $pegawai['nip']],
                [
                    'nama' => $pegawai['nama'],
                    'unit_kerja_id' => $unitKerja[$pegawai['unit']],
                    'jabatan' => $pegawai['jabatan'],
                    'foto_referensi_path' => $pegawai['wajah'] ? "referensi-wajah/{$pegawai['nip']}.jpg" : null,
                    'wajah_terdaftar' => $pegawai['wajah'],
                    'sumber_sinkron_terakhir' => now(),
                ],
            );
        }
    }
}
