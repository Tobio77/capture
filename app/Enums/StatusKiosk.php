<?php

namespace App\Enums;

enum StatusKiosk: string
{
    case Online = 'online';
    case Offline = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Offline => 'Offline',
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
