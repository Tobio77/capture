<?php

namespace App\Enums;

/**
 * Ketepatan waktu kedatangan (FR-TAP-07).
 *
 * Hanya berlaku untuk jenis Datang; absen Pulang tidak mengenal terlambat.
 */
enum StatusKetepatan: string
{
    case Tepat = 'tepat';
    case Terlambat = 'terlambat';

    public function label(): string
    {
        return match ($this) {
            self::Tepat => 'Tepat Waktu',
            self::Terlambat => 'Terlambat',
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
