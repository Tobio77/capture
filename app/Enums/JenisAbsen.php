<?php

namespace App\Enums;

enum JenisAbsen: string
{
    case Datang = 'datang';
    case Pulang = 'pulang';

    public function label(): string
    {
        return match ($this) {
            self::Datang => 'Datang',
            self::Pulang => 'Pulang',
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
