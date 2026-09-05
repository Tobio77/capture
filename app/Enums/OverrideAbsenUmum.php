<?php

namespace App\Enums;

/**
 * Override manual buka/tutup Absen Umum (FR-SET-07).
 *
 * Jalan keluar untuk kasus khusus: apel dadakan di luar jam bawaan, atau
 * penutupan lebih awal ketika kegiatan dipindah. Override SELALU menang atas
 * jadwal, dan berlaku hanya untuk hari itu — ia menempel pada sesi harian,
 * sehingga hari berikutnya lahir tanpa membawanya.
 */
enum OverrideAbsenUmum: string
{
    case Buka = 'buka';
    case Tutup = 'tutup';

    public function label(): string
    {
        return match ($this) {
            self::Buka => 'Dibuka paksa',
            self::Tutup => 'Ditutup paksa',
        };
    }

    public function terbuka(): bool
    {
        return $this === self::Buka;
    }

    /**
     * @return array<int, string>
     */
    public static function nilai(): array
    {
        return array_column(self::cases(), 'value');
    }
}
