<?php

namespace Database\Seeders;

use App\Enums\PeranPengguna;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Akun admin contoh untuk pengembangan lokal.
 * Kata sandi default "password" — WAJIB diganti sebelum deployment (lihat S27 hardening).
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $dinas = UnitKerja::where('kode', 'DISNAKER')->firstOrFail();
        $blkSurabaya = UnitKerja::where('kode', 'BLK-SBY')->firstOrFail();

        $daftar = [
            [
                'email' => 'superadmin@capture.test',
                'nama' => 'Superadmin SI-ABSEN',
                'role' => PeranPengguna::Superadmin,
                'unit_kerja_id' => null,
            ],
            [
                'email' => 'admin.dinas@capture.test',
                'nama' => 'Admin Dinas Disnakertrans',
                'role' => PeranPengguna::AdminDinas,
                'unit_kerja_id' => $dinas->id,
            ],
            [
                'email' => 'admin.blksby@capture.test',
                'nama' => 'Admin UPT BLK Surabaya',
                'role' => PeranPengguna::AdminUpt,
                'unit_kerja_id' => $blkSurabaya->id,
            ],
        ];

        foreach ($daftar as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'nama' => $user['nama'],
                    'role' => $user['role'],
                    'unit_kerja_id' => $user['unit_kerja_id'],
                    'password' => Hash::make('password'),
                    'aktif' => true,
                ],
            );
        }
    }
}
