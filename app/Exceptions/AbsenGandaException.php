<?php

namespace App\Exceptions;

use App\Models\Absensi;
use RuntimeException;

/**
 * Kehadiran untuk pasangan (event, pegawai, jenis) ini sudah tercatat.
 *
 * Sejak revisi FR-TAP-05 (S28a), tap kedua untuk jenis yang sama pada event
 * yang sama ditolak alih-alih menimpa baris yang ada. Alasannya bukan teknis
 * melainkan pertanggungjawaban: jam kehadiran yang sudah tercatat adalah bukti,
 * dan menimpanya secara diam-diam membuat jam itu dapat digeser hanya dengan
 * men-tap ulang — termasuk oleh orang yang datang terlambat lalu mengulang tap
 * setelah temannya mengabsenkan lebih dahulu.
 *
 * Membawa baris yang sudah ada supaya pemanggil dapat memberi tahu pegawai
 * pukul berapa ia sebenarnya sudah tercatat.
 */
class AbsenGandaException extends RuntimeException
{
    public function __construct(public readonly Absensi $tercatat)
    {
        parent::__construct(sprintf(
            'Kehadiran %s sudah tercatat pukul %s.',
            $tercatat->jenis->label(),
            $tercatat->waktu->format('H:i'),
        ));
    }

    /** Pesan siap tampil di layar titik absen. */
    public function pesan(): string
    {
        return sprintf(
            'Sudah absen %s pukul %s.',
            mb_strtolower($this->tercatat->jenis->label()),
            $this->tercatat->waktu->format('H:i'),
        );
    }
}
