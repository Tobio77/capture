<?php

namespace App\Enums;

/**
 * Status entry sebuah event (FR-EVT-04).
 *
 * Event tidak pernah dihapus, hanya ditutup — riwayat absensi menautnya.
 */
enum StatusEvent: string
{
    case Aktif = 'aktif';
    case Ditutup = 'ditutup';

    public function label(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif',
            self::Ditutup => 'Ditutup',
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
