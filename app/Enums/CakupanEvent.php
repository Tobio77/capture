<?php

namespace App\Enums;

use App\Services\EventAbsenService;

/**
 * Cakupan unit kerja sebuah event (FR-EVT-01).
 *
 * **Peninggalan sejak S49.** Setiap event kini berlaku bagi SELURUH dinas, dan
 * satu-satunya nilai yang pernah ditulis lagi adalah {@see self::SemuaUnit}.
 * Dua nilai lainnya hanya masih dikenali agar event yang lahir sebelum
 * perubahan itu tetap terbaca — kolomnya bukan lagi penentu siapa yang boleh
 * mengabsen (lihat {@see EventAbsenService::unitTercakup()}),
 * melainkan catatan sejarah tentang bagaimana sebuah event dulu dibuat.
 *
 * Enum ini tidak dihapus bersama jalur lamanya karena kolom `event_absen.
 * cakupan` masih memuat nilainya pada baris-baris lama, dan cast Eloquent akan
 * melempar begitu salah satu nilai itu hilang dari sini.
 */
enum CakupanEvent: string
{
    case Unit = 'unit';
    case SemuaUnit = 'semua_unit';
    case WilayahSurabaya = 'wilayah_surabaya';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Unit Terpilih',
            self::SemuaUnit => 'Semua Unit',
            self::WilayahSurabaya => 'Wilayah Kerja Surabaya',
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
