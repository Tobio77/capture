<?php

namespace App\Enums;

/**
 * Cakupan unit kerja sebuah event (FR-EVT-01).
 *
 * `SemuaUnit` dan `WilayahSurabaya` hanya boleh dipilih peran lintas unit;
 * Admin UPT terbatas pada unit kerjanya sendiri (FR-EVT-02).
 */
enum CakupanEvent: string
{
    case Unit = 'unit';
    case SemuaUnit = 'semua_unit';
    case WilayahSurabaya = 'wilayah_surabaya';

    /**
     * Unit kerja yang menyusun Wilayah Kerja Surabaya, dinyatakan sebagai
     * KODE, bukan id.
     *
     * Keempatnya berasal dari sinkronisasi WORKA dan diverifikasi ada pada
     * basis data produksi (lihat SDD §3.1). Id-nya berbeda antar lingkungan —
     * sebagian bernomor kecil karena ikut pada sinkronisasi awal, sebagian
     * bernomor besar karena menyusul — sehingga menautkannya lewat kode adalah
     * satu-satunya cara yang selamat melewati re-sync maupun basis data baru.
     *
     * Nama resmi WORKA tidak selalu sama dengan sebutan sehari-hari di kantor:
     *   BLK-SBY   → "UPT Balai Latihan Kerja di Surabaya"        (BLK Surabaya)
     *   UPT-K3    → "UPT Keselamatan Kerja"                      (UPT K2)
     *   UPT-BLPP  → "UPT Balai Latihan Pengembangan Produktivitas
     *                Tenaga Kerja di Surabaya"                   (UPT Balai Pengembangan Produktivitas)
     *   UPT-P2TK  → "UPT Pelayanan dan Perlindungan Tenaga Kerja"
     */
    public const array KODE_WILAYAH_SURABAYA = ['BLK-SBY', 'UPT-K3', 'UPT-BLPP', 'UPT-P2TK'];

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Unit Terpilih',
            self::SemuaUnit => 'Semua Unit',
            self::WilayahSurabaya => 'Wilayah Kerja Surabaya',
        };
    }

    /**
     * Kode unit kerja yang tertanam pada cakupan ini, atau array kosong bila
     * cakupannya tidak menentukan unitnya sendiri.
     *
     * @return array<int, string>
     */
    public function kodeUnitTertanam(): array
    {
        return match ($this) {
            self::WilayahSurabaya => self::KODE_WILAYAH_SURABAYA,
            default => [],
        };
    }

    /**
     * Cakupan yang daftar unitnya ditentukan sistem, bukan dicentang admin.
     *
     * Pivot unitnya tetap diisi — berbeda dari `SemuaUnit`, yang justru tidak
     * menyimpan baris pivot sama sekali — sehingga seluruh mesin yang membaca
     * cakupan lewat pivot (pencocokan kiosk, rekap, kode unit kerja per event)
     * bekerja tanpa perlu mengenali cakupan ini.
     */
    public function unitTertanam(): bool
    {
        return $this->kodeUnitTertanam() !== [];
    }

    /**
     * Cakupan yang melampaui satu unit kerja, sehingga hanya boleh dipilih
     * Superadmin dan Admin Dinas (FR-EVT-02).
     */
    public function lintasUnit(): bool
    {
        return $this !== self::Unit;
    }

    /**
     * @return array<int, string>
     */
    public static function nilai(): array
    {
        return array_column(self::cases(), 'value');
    }
}
