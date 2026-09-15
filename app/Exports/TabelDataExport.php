<?php

namespace App\Exports;

use App\Services\EksporService;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * "Unduh Data" sebagai .xlsx — tabel mentah apa adanya, BUKAN Laporan Resmi.
 *
 * Generik dan dipakai lebih dari satu menu (Laporan, Rekap, Absen Umum):
 * ketiganya sama-sama hanya butuh header + baris dengan gaya minimal (tebal
 * pada kepala tabel), berbeda dari {@see LaporanResmiExport} yang punya kop
 * surat, kesimpulan, dan rekomendasi bertemplat.
 */
class TabelDataExport implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithStyles
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

    /**
     * Nilai disaring lewat {@see EksporService::amankanFormula()} sebelum
     * ditulis — sebagian kolom (nama unit kerja, dst.) berasal dari isian
     * bebas admin, dan .xlsx sama rentannya terhadap formula injection
     * seperti CSV begitu dibuka Excel.
     */
    public function array(): array
    {
        return array_map(
            fn (array $baris) => array_map(EksporService::amankanFormula(...), $baris),
            $this->baris,
        );
    }

    /**
     * NIP ditulis sebagai TEKS, bukan dibiarkan tertebak sendiri oleh
     * PhpSpreadsheet.
     *
     * Value binder bawaan mengenali string yang isinya tampak seperti angka
     * (`is_numeric()`) dan menyimpannya sebagai sel numerik — untuk NIP 18
     * digit, akibatnya dua lapis: Excel menampilkannya dalam notasi ilmiah
     * ("1,98E+17"), DAN nilai float yang tersimpan sungguhan kehilangan
     * digit di belakang sebab presisi float hanya sekitar 15-17 digit
     * signifikan. Bukan cuma salah tampil — NIP-nya sendiri berubah begitu
     * berkasnya dibuka.
     *
     * Nilai yang benar-benar berupa PHP string (NIP, nama, unit kerja, dst.)
     * SELALU ditulis sebagai TEXT di sini, apa pun isinya; nilai numerik asli
     * (jumlah hadir, terlambat, dst. — sudah int/float sejak dari controller)
     * tetap lewat perilaku bawaan supaya tetap dapat dijumlah di Excel.
     */
    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return (new DefaultValueBinder)->bindValue($cell, $value);
    }

    public function styles(Worksheet $sheet): array
    {
        $kolomTerakhir = Coordinate::stringFromColumnIndex(count($this->judul));
        $sheet->getStyle("A1:{$kolomTerakhir}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$kolomTerakhir}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F1F5F9');
        $sheet->freezePane('A2');

        return [];
    }
}
