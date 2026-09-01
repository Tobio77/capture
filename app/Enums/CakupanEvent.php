<?php

namespace App\Enums;

/**
 * Cakupan unit kerja sebuah event (FR-EVT-01).
 *
 * `SemuaUnit` hanya boleh dipilih peran lintas unit; Admin UPT terbatas pada
 * unit kerjanya sendiri (FR-EVT-02).
 */
enum CakupanEvent: string
{
    case Unit = 'unit';
    case SemuaUnit = 'semua_unit';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Unit Terpilih',
            self::SemuaUnit => 'Semua Unit',
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
