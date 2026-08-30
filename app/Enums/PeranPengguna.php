<?php

namespace App\Enums;

/**
 * Peran akun admin SI-ABSEN (lihat docs/02-SRS-Absensi.md — matriks peran vs akses).
 */
enum PeranPengguna: string
{
    case Superadmin = 'superadmin';
    case AdminDinas = 'admin_dinas';
    case AdminUpt = 'admin_upt';

    public function label(): string
    {
        return match ($this) {
            self::Superadmin => 'Superadmin',
            self::AdminDinas => 'Admin Dinas',
            self::AdminUpt => 'Admin UPT',
        };
    }

    /**
     * Peran yang cakupannya meliputi seluruh unit kerja.
     */
    public function lintasUnit(): bool
    {
        return $this !== self::AdminUpt;
    }

    /**
     * @return array<int, string>
     */
    public static function nilai(): array
    {
        return array_column(self::cases(), 'value');
    }
}
