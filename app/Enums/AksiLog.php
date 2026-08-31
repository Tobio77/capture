<?php

namespace App\Enums;

/**
 * Jenis aksi yang dicatat pada audit trail (FR-AUTH-03).
 */
enum AksiLog: string
{
    case Masuk = 'masuk';
    case MasukGagal = 'masuk_gagal';
    case Keluar = 'keluar';
    case Buat = 'buat';
    case Ubah = 'ubah';
    case Hapus = 'hapus';

    public function label(): string
    {
        return match ($this) {
            self::Masuk => 'Masuk',
            self::MasukGagal => 'Percobaan Masuk Gagal',
            self::Keluar => 'Keluar',
            self::Buat => 'Membuat Data',
            self::Ubah => 'Mengubah Data',
            self::Hapus => 'Menghapus Data',
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
