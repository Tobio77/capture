<?php

namespace App\Exports;

use App\Services\EksporService;
use App\Services\Laporan\LaporanResmiService;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Laporan Resmi dalam bentuk Excel (.xlsx), satu sheet terformat rapi
 * (FR-LAP-04) — BUKAN dump tabel mentah seperti "Unduh Data".
 *
 * Seluruh isi ditulis lewat {@see self::registerEvents()} atas Worksheet
 * mentah, bukan lewat `array()` biasa: dokumen ini punya kop surat, judul,
 * tabel, dan prosa yang tidak sebaris-satu-baris seperti tabel data, sehingga
 * kendali penuh atas posisi sel dan penggabungannya lebih sesuai daripada
 * memaksakannya lewat baris array.
 */
class LaporanResmiExport implements FromArray, ShouldAutoSize, WithEvents, WithTitle
{
    use Exportable;

    protected const WARNA_NAVY = '0F2A43';

    protected const WARNA_TEAL = '0D9488';

    protected const WARNA_AMBER = 'B45309';

    protected const WARNA_REDUP_LEMBUT = 'F1F5F9';

    protected const WARNA_GARIS = 'CBD5E1';

    public function __construct(protected array $data) {}

    /**
     * Seluruh isi sesungguhnya ditulis di registerEvents(); ini hanya benih
     * minimal supaya paket Excel tahu sheet-nya perlu dibuat.
     */
    public function array(): array
    {
        return [['']];
    }

    public function title(): string
    {
        return 'Ringkasan Laporan';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $baris = $this->tulisKop($sheet);
                $baris = $this->tulisJudul($sheet, $baris);
                $baris = $this->tulisRingkasan($sheet, $baris);
                $baris = $this->tulisKesimpulan($sheet, $baris);
                $baris = $this->tulisRekomendasi($sheet, $baris);
                $baris = $this->tulisPengesahan($sheet, $baris);
                $this->tulisLampiranRincian($sheet, $baris);

                $sheet->getColumnDimension('A')->setWidth(42);
                foreach (['B', 'C', 'D', 'E', 'F', 'G'] as $kolom) {
                    $sheet->getColumnDimension($kolom)->setWidth(13);
                }
            },
        ];
    }

    protected function tulisKop(Worksheet $sheet): int
    {
        $logo = resource_path('images/logo-pemprov-jatim.png');

        if (is_file($logo)) {
            $gambar = new Drawing;
            $gambar->setPath($logo);
            $gambar->setHeight(56);
            $gambar->setCoordinates('A1');
            $gambar->setOffsetX(4);
            $gambar->setOffsetY(4);
            $gambar->setWorksheet($sheet);
        }

        $sheet->mergeCells('B1:G1');
        $sheet->setCellValue('B1', 'PEMERINTAH PROVINSI JAWA TIMUR');
        $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(12)->getColor()->setRGB(self::WARNA_NAVY);

        $sheet->mergeCells('B2:G2');
        $sheet->setCellValue('B2', 'DINAS TENAGA KERJA DAN TRANSMIGRASI');
        $sheet->getStyle('B2')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB(self::WARNA_NAVY);

        $sheet->mergeCells('B3:G3');
        $sheet->setCellValue('B3', 'Jln. Dukuh Menanggal 124-126, Gayungan, Surabaya, Jawa Timur 60234');
        $sheet->getStyle('B3')->getFont()->setSize(8)->getColor()->setRGB('64748B');

        $sheet->mergeCells('B4:G4');
        $sheet->setCellValue('B4', 'Tlp (031) 8290005, Laman disnakertrans.jatimprov.go.id, Pos-el disnakertrans@jatimprov.go.id');
        $sheet->getStyle('B4')->getFont()->setSize(8)->getColor()->setRGB('64748B');

        $sheet->getRowDimension(1)->setRowHeight(18);
        $sheet->getRowDimension(2)->setRowHeight(20);

        // Garis ganda tipis-tebal, meniru border-double kop cetak PDF.
        $sheet->getStyle('A5:G5')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::WARNA_NAVY);
        $sheet->getStyle('A6:G6')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(self::WARNA_NAVY);

        return 8;
    }

    protected function tulisJudul(Worksheet $sheet, int $baris): int
    {
        $sheet->mergeCells("A{$baris}:G{$baris}");
        $sheet->setCellValue("A{$baris}", 'LAPORAN KEHADIRAN PEGAWAI');
        $sheet->getStyle("A{$baris}")->getFont()->setBold(true)->setSize(13)->getColor()->setRGB(self::WARNA_NAVY);
        $sheet->getStyle("A{$baris}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $baris++;

        $sheet->mergeCells("A{$baris}:G{$baris}");
        $sheet->setCellValue("A{$baris}", "Periode: {$this->data['periode_label']}   |   Unit Kerja: {$this->data['cakupan']}");
        $sheet->getStyle("A{$baris}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A{$baris}")->getFont()->setSize(10);

        return $baris + 2;
    }

    protected function tulisRingkasan(Worksheet $sheet, int $baris): int
    {
        $sheet->setCellValue("A{$baris}", 'RINGKASAN DATA');
        $sheet->getStyle("A{$baris}")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB(self::WARNA_NAVY);
        $baris++;

        $header = ['Unit Kerja', 'Pegawai', 'Hadir', 'Tepat', 'Terlambat', 'Tanpa Ket.', 'Kehadiran'];
        $kolom = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

        foreach ($header as $i => $judul) {
            $sel = "{$kolom[$i]}{$baris}";
            $sheet->setCellValue($sel, $judul);
            $sheet->getStyle($sel)->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('475569');
            $sheet->getStyle($sel)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::WARNA_REDUP_LEMBUT);
            $sheet->getStyle($sel)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::WARNA_GARIS);
            $sheet->getStyle($sel)->getAlignment()->setHorizontal($i === 0 ? Alignment::HORIZONTAL_LEFT : Alignment::HORIZONTAL_RIGHT);
        }
        $barisHeader = $baris;
        $baris++;

        foreach ($this->data['per_unit'] as $unit) {
            // Nama unit kerja disaring lewat amankanFormula(): satu-satunya
            // teks bebas pada tabel ini — sisanya angka — dan bisa diisi
            // Admin UPT lewat Setting Unit Kerja, peran yang lebih rendah
            // daripada superadmin yang kelak membuka berkasnya.
            $nilai = [
                EksporService::amankanFormula($unit['nama']),
                $unit['pegawai'],
                $unit['hadir'],
                $unit['tepat'],
                $unit['terlambat'],
                $unit['tanpa_keterangan'],
                LaporanResmiService::formatPersen($unit['tingkat_kehadiran']).'%',
            ];

            foreach ($nilai as $i => $isi) {
                $sel = "{$kolom[$i]}{$baris}";
                $sheet->setCellValue($sel, $isi);
                $sheet->getStyle($sel)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::WARNA_GARIS);
                $sheet->getStyle($sel)->getAlignment()->setHorizontal($i === 0 ? Alignment::HORIZONTAL_LEFT : Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle($sel)->getFont()->setSize(9);
            }

            // Terlambat > 0 disorot amber, tepat selalu teal — sama seperti
            // dua renderer lain, supaya ketiganya tidak diam-diam berbeda gaya.
            if ($unit['terlambat'] > 0) {
                $sheet->getStyle("E{$baris}")->getFont()->setBold(true)->getColor()->setRGB(self::WARNA_AMBER);
            }
            $sheet->getStyle("D{$baris}")->getFont()->setBold(true)->getColor()->setRGB(self::WARNA_TEAL);

            $baris++;
        }

        if ($this->data['per_unit'] === []) {
            $sheet->mergeCells("A{$baris}:G{$baris}");
            $sheet->setCellValue("A{$baris}", 'Tidak ada data pada cakupan dan periode ini.');
            $sheet->getStyle("A{$baris}")->getFont()->setItalic(true)->getColor()->setRGB('94A3B8');
            $sheet->getStyle("A{$baris}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $baris++;
        } else {
            $total = $this->data['total'];
            $nilaiTotal = [
                'Total', $total['pegawai'], $total['hadir'], $total['tepat'], $total['terlambat'],
                $total['tanpa_keterangan'], LaporanResmiService::formatPersen($total['tingkat_kehadiran']).'%',
            ];
            foreach ($nilaiTotal as $i => $isi) {
                $sel = "{$kolom[$i]}{$baris}";
                $sheet->setCellValue($sel, $isi);
                $sheet->getStyle($sel)->getFont()->setBold(true)->setSize(9);
                $sheet->getStyle($sel)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::WARNA_REDUP_LEMBUT);
                $sheet->getStyle($sel)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::WARNA_GARIS);
                $sheet->getStyle($sel)->getAlignment()->setHorizontal($i === 0 ? Alignment::HORIZONTAL_LEFT : Alignment::HORIZONTAL_RIGHT);
            }
            $baris++;
        }

        // Baris kepala tabel tetap terlihat saat sheet digulir jauh ke bawah.
        $sheet->freezePane('A'.($barisHeader + 1));

        return $baris + 1;
    }

    protected function tulisKesimpulan(Worksheet $sheet, int $baris): int
    {
        $sheet->setCellValue("A{$baris}", 'KESIMPULAN');
        $sheet->getStyle("A{$baris}")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB(self::WARNA_NAVY);
        $baris++;

        $sheet->mergeCells("A{$baris}:G".($baris + 1));
        $sheet->setCellValue("A{$baris}", EksporService::amankanFormula($this->data['kesimpulan']));
        $sheet->getStyle("A{$baris}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle("A{$baris}")->getFont()->setSize(10);

        return $baris + 3;
    }

    protected function tulisRekomendasi(Worksheet $sheet, int $baris): int
    {
        $sheet->setCellValue("A{$baris}", 'REKOMENDASI');
        $sheet->getStyle("A{$baris}")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB(self::WARNA_NAVY);
        $baris++;

        $rekomendasi = $this->data['rekomendasi'];

        if ($rekomendasi['tidak_ada_masalah']) {
            $sheet->mergeCells("A{$baris}:G".($baris + 1));
            $sheet->setCellValue(
                "A{$baris}",
                'Seluruh unit kerja menunjukkan tingkat kehadiran yang baik pada periode ini. '
                .'Tidak terdapat rekomendasi tindak lanjut khusus.',
            );
            $sheet->getStyle("A{$baris}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getStyle("A{$baris}")->getFont()->setSize(10)->getColor()->setRGB('047857');
            $sheet->getStyle("A{$baris}:G".($baris + 1))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('ECFDF5');

            return $baris + 3;
        }

        foreach ([
            ['a. Tingkat Kehadiran di Bawah Ambang Batas', $rekomendasi['kehadiran_rendah']],
            ['b. Tingkat Keterlambatan di Atas Ambang Batas', $rekomendasi['keterlambatan_tinggi']],
        ] as [$judul, $daftar]) {
            if ($daftar === []) {
                continue;
            }

            $sheet->setCellValue("A{$baris}", $judul);
            $sheet->getStyle("A{$baris}")->getFont()->setBold(true)->setSize(10)->getColor()->setRGB('334155');
            $baris++;

            foreach ($daftar as $r) {
                $sheet->mergeCells("A{$baris}:G{$baris}");
                $sheet->setCellValue("A{$baris}", '• '.EksporService::amankanFormula($r['kalimat']));
                $sheet->getStyle("A{$baris}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
                $sheet->getStyle("A{$baris}")->getFont()->setSize(9.5);
                $sheet->getRowDimension($baris)->setRowHeight(28);
                $baris++;
            }

            $baris++;
        }

        return $baris + 1;
    }

    protected function tulisPengesahan(Worksheet $sheet, int $baris): int
    {
        $baris += 2;
        $sheet->mergeCells("E{$baris}:G{$baris}");
        $sheet->setCellValue("E{$baris}", 'Surabaya, '.now()->translatedFormat('d F Y'));
        $sheet->getStyle("E{$baris}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $baris += 4;
        $sheet->mergeCells("E{$baris}:G{$baris}");
        $sheet->setCellValue("E{$baris}", '( _________________________ )');
        $sheet->getStyle("E{$baris}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $baris++;
        $sheet->mergeCells("E{$baris}:G{$baris}");
        $sheet->setCellValue("E{$baris}", 'NIP. ');
        $sheet->getStyle("E{$baris}")->getFont()->setSize(9)->getColor()->setRGB('64748B');
        $sheet->getStyle("E{$baris}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        return $baris + 1;
    }

    /**
     * Lampiran rincian kehadiran: satu baris per pegawai per sesi absen,
     * lengkap dengan jam masuk dan jam pulang yang tercatat.
     *
     * Ditulis setelah pengesahan, mengikuti urutan yang sama dengan PDF dan
     * Word: yang ditandatangani adalah ringkasan di atas, dan lampiran adalah
     * bukti pendukungnya.
     *
     * NIP ditulis EKSPLISIT sebagai string (`setCellValueExplicit`), bukan
     * dibiarkan tertebak PhpSpreadsheet: 18 digit yang ditebak sebagai angka
     * ditampilkan Excel sebagai "1,98E+17" dan kehilangan digit di belakang —
     * NIP-nya sendiri berubah begitu berkasnya dibuka. Pagar yang sama sudah
     * berdiri pada TabelDataExport.
     */
    protected function tulisLampiranRincian(Worksheet $sheet, int $baris): void
    {
        if (count($this->data['rincian']) === 0) {
            return;
        }

        $baris += 2;

        $sheet->setCellValue("A{$baris}", 'LAMPIRAN — RINCIAN KEHADIRAN PER PEGAWAI');
        $sheet->getStyle("A{$baris}")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB(self::WARNA_NAVY);
        $baris++;

        $sheet->setCellValue(
            "A{$baris}",
            'Jam masuk dan jam pulang sebagaimana tercatat sistem. Pegawai tanpa catatan '
            .'kehadiran tidak muncul di sini; jumlah ketidakhadirannya terbaca pada Ringkasan Data.',
        );
        $sheet->getStyle("A{$baris}")->getFont()->setSize(9)->getColor()->setRGB('64748B');
        $baris += 2;

        $header = ['NIP', 'Nama', 'Unit Kerja', 'Tanggal', 'Kegiatan', 'Masuk', 'Pulang', 'Status'];
        $kolom = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

        foreach ($header as $i => $judul) {
            $sel = "{$kolom[$i]}{$baris}";
            $sheet->setCellValue($sel, $judul);
            $sheet->getStyle($sel)->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('475569');
            $sheet->getStyle($sel)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::WARNA_REDUP_LEMBUT);
            $sheet->getStyle($sel)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::WARNA_GARIS);
            $sheet->getStyle($sel)->getAlignment()->setHorizontal($i >= 5 ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT);
        }

        $baris++;

        foreach ($this->data['rincian'] as $isi) {
            $nilai = [
                $isi['nip'],
                $isi['nama'],
                $isi['unit_kerja'] ?? '—',
                $isi['tanggal_label'] ?? '—',
                $isi['kegiatan'] ?? '—',
                $isi['jam_masuk'] ?? '—',
                $isi['jam_pulang'] ?? '—',
                $isi['status_label'] ?? '—',
            ];

            foreach ($nilai as $i => $teks) {
                $sel = "{$kolom[$i]}{$baris}";

                /*
                 * Dua pagar sekaligus, dan keduanya disengaja. `TYPE_STRING`
                 * menahan Excel menghitung ulang isinya sebagai formula;
                 * `amankanFormula()` menahannya sekali lagi pada tingkat teks,
                 * sejalan dengan tabel Ringkasan di atas. Nama pegawai berasal
                 * dari sinkronisasi WORKA — kolom yang tidak sepenuhnya di
                 * bawah kendali aplikasi ini (CWE-1236).
                 */
                $sheet->setCellValueExplicit(
                    $sel,
                    EksporService::amankanFormula((string) $teks),
                    DataType::TYPE_STRING,
                );
                $sheet->getStyle($sel)->getFont()->setSize(9);
                $sheet->getStyle($sel)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::WARNA_GARIS);
                $sheet->getStyle($sel)->getAlignment()->setHorizontal($i >= 5 ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT);
            }

            $baris++;
        }

        if ($this->data['rincian_dipotong'] > 0) {
            $baris++;
            $sheet->setCellValue("A{$baris}", sprintf(
                '%s baris berikutnya tidak dimuat agar dokumen tetap dapat dirakit. '
                .'Gunakan "Unduh Data" pada menu Laporan untuk memperoleh seluruhnya.',
                number_format($this->data['rincian_dipotong'], 0, ',', '.'),
            ));
            $sheet->getStyle("A{$baris}")->getFont()->setSize(9)->setItalic(true)->getColor()->setRGB('64748B');
        }
    }
}
