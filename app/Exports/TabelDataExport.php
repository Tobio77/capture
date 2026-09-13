<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * "Unduh Data" sebagai .xlsx — tabel mentah apa adanya, BUKAN Laporan Resmi.
 *
 * Generik dan dipakai lebih dari satu menu (Laporan, Rekap): keduanya sama-
 * sama hanya butuh header + baris dengan gaya minimal (tebal pada kepala
 * tabel), berbeda dari {@see LaporanResmiExport} yang punya kop surat,
 * kesimpulan, dan rekomendasi bertemplat.
 */
class TabelDataExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles
{
    use Exportable;

    /**
     * @param  array<int, string>  $judul
     * @param  array<int, array<int, mixed>>  $baris
     */
    public function __construct(protected array $judul, protected array $baris) {}

    public function headings(): array
    {
        return $this->judul;
    }

    public function array(): array
    {
        return $this->baris;
    }

    public function styles(Worksheet $sheet): array
    {
        $kolomTerakhir = Coordinate::stringFromColumnIndex(count($this->judul));
        $sheet->getStyle("A1:{$kolomTerakhir}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$kolomTerakhir}1")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F1F5F9');
        $sheet->freezePane('A2');

        return [];
    }
}
