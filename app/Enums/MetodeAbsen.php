<?php

namespace App\Enums;

enum MetodeAbsen: string
{
    case Manual = 'manual';
    case Rfid = 'rfid';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Rfid => 'Tap RFID',
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
