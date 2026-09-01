<?php

namespace App\Enums;

/**
 * Preset kompresi foto absen (FR-SET-04).
 *
 * Preset dipilih admin, tetapi yang benar-benar dipakai kiosk saat menyusut
 * foto adalah dimensi maksimum dan kualitas JPEG di bawah ini. Angkanya
 * dikunci di sini, bukan di layar, supaya kiosk dan server membaca sumber yang
 * sama.
 *
 * NFR-06 membatasi satu foto absen tersimpan pada ~90 KB. Ketiga preset di
 * bawah diukur — bukan diperkirakan — terhadap 5 foto uji 1280×960 beragam
 * kerumitan, dan preset terbesar (Tinggi) berhenti di 54 KB pada kasus
 * terburuk, menyisakan margin ~40% terhadap batas itu.
 *
 * Margin sengaja dipertahankan karena penyusutan sesungguhnya dilakukan
 * `canvas.toBlob()` di browser kiosk, yang tabel kuantisasinya berbeda dari
 * GD yang dipakai saat pengukuran; selisihnya tertampung di dalam margin.
 */
enum KompresiFoto: string
{
    case Ringan = 'ringan';
    case Sedang = 'sedang';
    case Tinggi = 'tinggi';

    /** Batas ukuran satu foto absen tersimpan menurut NFR-06, dalam KB. */
    public const int BATAS_UKURAN_KB = 90;

    /**
     * Ukuran kasus terburuk hasil pengukuran, dalam KB.
     *
     * Dipakai sebagai invarian yang diuji: tidak boleh ada preset yang
     * melampaui BATAS_UKURAN_KB. Perbarui angka ini bila dimensi atau
     * kualitas diubah, dan ukur ulang — jangan ditaksir.
     */
    public function ukuranTerburukKb(): int
    {
        return match ($this) {
            self::Ringan => 24,
            self::Sedang => 35,
            self::Tinggi => 54,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Ringan => 'Ringan',
            self::Sedang => 'Sedang',
            self::Tinggi => 'Tinggi',
        };
    }

    /** Sisi terpanjang foto setelah disusutkan, dalam piksel. */
    public function dimensiMaks(): int
    {
        return match ($this) {
            self::Ringan => 480,
            self::Sedang => 560,
            self::Tinggi => 640,
        };
    }

    /** Kualitas JPEG 1–100. */
    public function kualitas(): int
    {
        return match ($this) {
            self::Ringan => 70,
            self::Sedang => 75,
            self::Tinggi => 80,
        };
    }

    /**
     * Rentang ukuran berkas hasil pengukuran, bukan perkiraan.
     *
     * Diukur atas 5 foto uji 1280×960 dengan tingkat kerumitan berbeda;
     * pemandangan berdetail padat berada di ujung atas rentang, sedangkan
     * wajah dari jarak dekat dengan latar kantor cenderung di ujung bawah.
     */
    public function estimasi(): string
    {
        return match ($this) {
            self::Ringan => '14–24 KB per foto',
            self::Sedang => '21–35 KB per foto',
            self::Tinggi => '30–54 KB per foto',
        };
    }

    public function keterangan(): string
    {
        return match ($this) {
            self::Ringan => 'Paling hemat ruang penyimpanan, masih cukup jelas sebagai bukti kehadiran.',
            self::Sedang => 'Keseimbangan antara ketajaman dan ukuran berkas.',
            self::Tinggi => 'Paling tajam. Tetap di bawah batas penyimpanan ~90 KB per foto (NFR-06).',
        };
    }

    /**
     * Bentuk siap kirim ke layar admin maupun kiosk.
     *
     * @return array<string, mixed>
     */
    public function rincian(): array
    {
        return [
            'nilai' => $this->value,
            'label' => $this->label(),
            'dimensi_maks' => $this->dimensiMaks(),
            'kualitas' => $this->kualitas(),
            'estimasi' => $this->estimasi(),
            'keterangan' => $this->keterangan(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function daftar(): array
    {
        return array_map(fn (self $preset) => $preset->rincian(), self::cases());
    }
}
