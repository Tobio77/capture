<?php

namespace App\Support;

use App\Enums\PeranPengguna;
use App\Models\User;

/**
 * Sumber tunggal struktur menu Panel Admin (docs/04-UIUX-Absensi.md §2.1),
 * disaring sesuai peran pengguna (FR-AUTH-02).
 *
 * Penyaringan di sini hanya menyembunyikan menu; penegakan akses tetap
 * dilakukan middleware 'peran' pada definisi route.
 */
class MenuNavigasi
{
    /**
     * @return array<int, array{label: string, ikon: string, anak?: array<int, array{label: string, url: string, rute: string}>, url?: string, rute?: string}>
     */
    public static function untuk(User $pengguna): array
    {
        $semuaPeran = PeranPengguna::cases();
        $lintasUnit = [PeranPengguna::Superadmin, PeranPengguna::AdminDinas];

        $struktur = [
            [
                'label' => 'Dashboard',
                'ikon' => 'dashboard',
                'rute' => 'dashboard',
                'peran' => $semuaPeran,
            ],
            [
                'label' => 'Kelola Absen',
                'ikon' => 'absen',
                'peran' => $semuaPeran,
                'anak' => [
                    ['label' => 'Daftar Event', 'rute' => 'event.index', 'peran' => $semuaPeran],
                    ['label' => 'Rekap Absen', 'rute' => 'rekap.index', 'peran' => $semuaPeran],
                    ['label' => 'Setting Absen', 'rute' => 'setting-absen.index', 'peran' => $lintasUnit],
                    ['label' => 'Setting Unit Kerja', 'rute' => 'unit-kerja.index', 'peran' => $semuaPeran],
                    ['label' => 'Integrasi WORKA', 'rute' => 'setting-worka.edit', 'peran' => $lintasUnit],
                ],
            ],
            [
                'label' => 'Kelola Pegawai',
                'ikon' => 'pegawai',
                'rute' => 'pegawai.index',
                'peran' => $semuaPeran,
            ],
            [
                /*
                 * Akun admin hanya boleh disentuh Superadmin; Admin Dinas
                 * terbatas pada perangkat absen, yang menempati menunya
                 * sendiri (matriks peran SRS §6).
                 */
                'label' => 'Kelola User / Role',
                'ikon' => 'pengguna',
                'rute' => 'pengguna.index',
                'peran' => [PeranPengguna::Superadmin],
            ],
            [
                'label' => 'Perangkat Absen',
                'ikon' => 'pengguna',
                'rute' => 'perangkat.index',
                'peran' => $lintasUnit,
            ],
            [
                'label' => 'Laporan',
                'ikon' => 'laporan',
                'rute' => 'laporan.index',
                'peran' => $semuaPeran,
            ],
        ];

        return self::saring($struktur, $pengguna->role);
    }

    /**
     * @param  array<int, array<string, mixed>>  $struktur
     * @return array<int, array<string, mixed>>
     */
    protected static function saring(array $struktur, PeranPengguna $peran): array
    {
        $menu = [];

        foreach ($struktur as $item) {
            if (! in_array($peran, $item['peran'], strict: true)) {
                continue;
            }

            $anak = isset($item['anak']) ? self::saring($item['anak'], $peran) : [];

            // Menu induk tanpa anak yang tersisa tidak perlu ditampilkan.
            if (isset($item['anak']) && $anak === []) {
                continue;
            }

            $menu[] = array_filter([
                'label' => $item['label'],
                'ikon' => $item['ikon'] ?? null,
                'rute' => $item['rute'] ?? null,
                'url' => isset($item['rute']) ? route($item['rute']) : null,
                'anak' => $anak !== [] ? $anak : null,
            ], fn ($nilai) => $nilai !== null);
        }

        return $menu;
    }
}
