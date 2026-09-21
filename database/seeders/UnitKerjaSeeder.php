<?php

namespace Database\Seeders;

use App\Models\UnitKerja;
use App\Services\KodeUnitService;
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

        $this->terbitkanKodePerangkat();
    }

    /**
     * Terbitkan kode perangkat bagi unit level teratas yang aktif (FR-EVT-03).
     *
     * Tanpa ini, instalasi yang dimulai dari seeder — pengembangan lokal, uji
     * e2e, dan deployment baru yang belum pernah menyinkronkan WORKA — berdiri
     * tanpa satu kode pun, dan tidak ada satu perangkat absen pun yang dapat
     * masuk. Migration S49 hanya menambal basis data yang SUDAH berisi unit;
     * jalur "mulai dari nol" tidak pernah melewatinya.
     *
     * Unit yang sudah punya kode tidak disentuh: seeder kerap dijalankan ulang
     * pada basis data yang sama, dan mengganti kode diam-diam berarti seluruh
     * perangkat yang sudah dibekali kode gagal masuk tanpa penjelasan.
     */
    protected function terbitkanKodePerangkat(): void
    {
        $kode = app(KodeUnitService::class);

        UnitKerja::query()
            ->levelTeratas()
            ->aktif()
            ->whereNull('kode_perangkat')
            ->get()
            ->each(fn (UnitKerja $unit) => $kode->pastikanAda($unit));
    }
}
