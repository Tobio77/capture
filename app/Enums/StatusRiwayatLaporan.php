<?php

namespace App\Enums;

/**
 * Status satu permintaan Generate Laporan Resmi (FR-LAP-04, revisi antrian).
 *
 * Empat keadaan, dan urutannya selalu maju — tidak pernah mundur:
 * Antre → Diproses → Selesai (berkas siap diunduh) atau Gagal (dengan
 * pesan galat tersimpan, bukan dihapus begitu saja).
 */
enum StatusRiwayatLaporan: string
{
    case Antre = 'antre';
    case Diproses = 'diproses';
    case Selesai = 'selesai';
    case Gagal = 'gagal';

    public function label(): string
    {
        return match ($this) {
            self::Antre => 'Antre',
            self::Diproses => 'Diproses',
            self::Selesai => 'Selesai',
            self::Gagal => 'Gagal',
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
