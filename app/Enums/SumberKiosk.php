<?php

namespace App\Enums;

/**
 * Asal-usul sebuah perangkat absen.
 *
 * `Terdaftar` berarti admin mendaftarkannya lebih dahulu dan menyerahkan kode
 * aktivasi kepada petugas di lokasi. `AdHoc` berarti perangkat itu masuk
 * sendiri selagi "Wajib Kode Aktivasi" dimatikan (FR-SET-06) — berguna pada
 * keadaan darurat, tetapi tidak pernah melewati peninjauan siapa pun, sehingga
 * harus tetap terbaca sebagai perangkat yang berbeda kelas.
 */
enum SumberKiosk: string
{
    case Terdaftar = 'terdaftar';
    case AdHoc = 'ad_hoc';

    public function label(): string
    {
        return match ($this) {
            self::Terdaftar => 'Terdaftar',
            self::AdHoc => 'Ad-hoc',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function nilai(): array
    {
        return array_column(self::cases(), 'value');
    }
}
