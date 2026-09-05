<?php

namespace App\Enums;

/**
 * Jenis aksi yang dicatat pada audit trail (FR-AUTH-03).
 * Kolom `aksi` pada log_aktivitas berupa string — enum ini yang menjaga daftar nilainya.
 */
enum AksiLog: string
{
    case Masuk = 'masuk';
    case MasukGagal = 'masuk_gagal';
    case Keluar = 'keluar';
    case Buat = 'buat';
    case Ubah = 'ubah';
    case Hapus = 'hapus';
    case AktivasiKiosk = 'aktivasi_kiosk';
    case AktivasiKioskGagal = 'aktivasi_kiosk_gagal';
    case LepasKiosk = 'lepas_kiosk';
    case GabungEvent = 'gabung_event';
    case GabungEventGagal = 'gabung_event_gagal';
    case KeluarEvent = 'keluar_event';

    public function label(): string
    {
        return match ($this) {
            self::Masuk => 'Masuk',
            self::MasukGagal => 'Percobaan Masuk Gagal',
            self::Keluar => 'Keluar',
            self::Buat => 'Membuat Data',
            self::Ubah => 'Mengubah Data',
            self::Hapus => 'Menghapus Data',
            self::AktivasiKiosk => 'Aktivasi Perangkat Absen',
            self::AktivasiKioskGagal => 'Aktivasi Perangkat Absen Gagal',
            self::LepasKiosk => 'Pelepasan Perangkat Absen',
            self::GabungEvent => 'Perangkat Bergabung ke Event',
            self::GabungEventGagal => 'Percobaan Bergabung ke Event Gagal',
            self::KeluarEvent => 'Perangkat Keluar dari Event',
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
