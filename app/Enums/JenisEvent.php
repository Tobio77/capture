<?php

namespace App\Enums;

/**
 * Jenis event absensi.
 *
 * `Kegiatan` adalah event yang dibuat admin lewat menu Daftar Event: apel,
 * rapat, pelatihan. `Umum` adalah sesi absen harian yang dibuka sistem
 * sendiri ketika tidak ada kegiatan yang sedang berjalan — pegawai tetap
 * dapat mencatat kehadiran rutinnya tanpa admin perlu membuat event lebih
 * dahulu.
 *
 * Sesi umum dibuat satu per unit kerja per tanggal, sehingga kunci unik
 * (event, pegawai, jenis) pada tabel absensi tetap berarti "satu kali datang
 * per hari".
 */
enum JenisEvent: string
{
    case Kegiatan = 'kegiatan';
    case Umum = 'umum';

    public function label(): string
    {
        return match ($this) {
            self::Kegiatan => 'Kegiatan',
            self::Umum => 'Absen Umum',
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
