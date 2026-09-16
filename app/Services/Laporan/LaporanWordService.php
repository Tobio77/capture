<?php

namespace App\Services\Laporan;

use Illuminate\Support\Carbon;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan Resmi dalam bentuk Word (.docx), A4 potret (FR-LAP-04).
 *
 * Struktur bagiannya sama persis dengan renderer PDF ({@see
 * resources/views/cetak/laporan-resmi.blade.php}) dan Excel ({@see
 * LaporanExcelExport}) — ketiganya membaca struktur data yang sama dari
 * {@see LaporanResmiService::susun()}, hanya cara menuliskannya yang berbeda
 * mengikuti kemampuan masing-masing format.
 */
class LaporanWordService
{
    protected const WARNA_NAVY = '0F2A43';

    protected const WARNA_TEAL = '0D9488';

    protected const WARNA_AMBER = 'B45309';

    protected const WARNA_REDUP = '64748B';

    public function unduh(array $data, string $namaBerkas): StreamedResponse
    {
        $phpWord = $this->rakit($data);

        return response()->streamDownload(function () use ($phpWord) {
            IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
        }, $namaBerkas, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /**
     * Bytes .docx mentah — dipakai BuatLaporanResmiJob untuk menyimpan
     * berkasnya ke disk (Riwayat Laporan), bukan menstrimnya langsung ke
     * peramban seperti {@see self::unduh()}.
     */
    public function bytes(array $data): string
    {
        $phpWord = $this->rakit($data);

        $sementara = tempnam($this->direktoriSementara(), 'laporan-resmi-docx');
        IOFactory::createWriter($phpWord, 'Word2007')->save($sementara);
        $isi = file_get_contents($sementara);
        unlink($sementara);

        return $isi;
    }

    /**
     * Susun dokumennya, tanpa memutuskan apa yang terjadi sesudahnya
     * (distream ke peramban, atau ditulis ke disk sebagai berkas).
     */
    protected function rakit(array $data): PhpWord
    {
        Settings::setTempDir($this->direktoriSementara());

        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('DejaVu Sans');
        $phpWord->setDefaultFontSize(10);

        $section = $phpWord->addSection([
            'orientation' => 'portrait',
            'marginTop' => 1100,
            'marginBottom' => 1100,
            'marginLeft' => 1200,
            'marginRight' => 1200,
        ]);

        $this->tulisKop($section);
        $this->tulisJudul($section, $data);
        $this->tulisRingkasan($section, $data);
        $this->tulisKesimpulan($section, $data);
        $this->tulisRekomendasi($section, $data);
        $this->tulisPengesahan($section, $data);
        $this->tulisLampiranRincian($section, $data);
        $this->tulisKaki($section, $data);

        return $phpWord;
    }

    /**
     * PhpWord menulis .docx sementara ke direktori temp SISTEM
     * (`sys_get_temp_dir()`) sebelum menstriminya ke peramban. Itu bergantung
     * pada variabel lingkungan `TMP`/`TEMP` proses PHP yang sedang berjalan —
     * pada sebagian server Windows (termasuk PHP built-in server tanpa
     * variabel itu diteruskan) nilainya jatuh kembali ke direktori sistem
     * yang tidak boleh ditulis akun aplikasi, dan `Settings::setTempDir()`-
     * lah yang mengambil alih keputusan itu daripada bergantung pada
     * lingkungan yang tidak selalu terjamin. Dipakai direktori storage
     * aplikasi sendiri, yang sudah pasti dapat ditulis di lingkungan mana
     * pun aplikasi ini berjalan.
     *
     * Dibuat lebih dulu di sini, bukan diserahkan ke PhpWord: subdirektori
     * ACAK yang dibuatnya sendiri di bawah nilai ini (lihat
     * `AbstractWriter::getTempFile()`) memakai `mkdir()` TANPA opsi
     * rekursif, sehingga gagal begitu saja bila induknya belum ada.
     */
    protected function direktoriSementara(): string
    {
        $direktori = storage_path('app/phpword-tmp');

        if (! is_dir($direktori)) {
            mkdir($direktori, recursive: true);
        }

        return $direktori;
    }

    protected function tulisKop(Section $section): void
    {
        // Tabel tanpa garis, semata alat tata letak: logo di kiri, teks kop
        // di kanan, sejajar vertikal — Word tidak punya "float" seperti CSS.
        $tabel = $section->addTable(['cellMargin' => 0]);
        $tabel->addRow();

        $selLogo = $tabel->addCell(700, ['valign' => 'center']);
        $logo = resource_path('images/logo-pemprov-jatim.png');

        if (is_file($logo)) {
            $selLogo->addImage($logo, ['width' => 50, 'height' => 72]);
        }

        $selTeks = $tabel->addCell(9000, ['valign' => 'center']);
        $selTeks->addText('PEMERINTAH PROVINSI JAWA TIMUR', [
            'bold' => true, 'size' => 12, 'color' => self::WARNA_NAVY,
        ]);
        $selTeks->addText('DINAS TENAGA KERJA DAN TRANSMIGRASI', [
            'bold' => true, 'size' => 14, 'color' => self::WARNA_NAVY,
        ]);
        $selTeks->addText('Jln. Dukuh Menanggal 124-126, Gayungan, Surabaya, Jawa Timur 60234', [
            'size' => 8, 'color' => self::WARNA_REDUP,
        ]);
        $selTeks->addText('Tlp (031) 8290005, Laman disnakertrans.jatimprov.go.id, Pos-el disnakertrans@jatimprov.go.id', [
            'size' => 8, 'color' => self::WARNA_REDUP,
        ]);

        // Garis ganda di bawah kop, mengikuti prototipe. `addLine()` PhpWord
        // menggambar bentuk vektor, bukan aturan paragraf — dua garis tipis
        // berdekatan adalah cara paling portabel meniru "border-double" CSS
        // tanpa bergantung pada dukungan pembaca Word tertentu atas gaya
        // border OOXML yang jarang dipakai.
        $section->addLine(['weight' => 1, 'width' => 468, 'height' => 0, 'color' => self::WARNA_NAVY]);
        $section->addTextBreak(1, ['size' => 2]);
        $section->addLine(['weight' => 3, 'width' => 468, 'height' => 0, 'color' => self::WARNA_NAVY]);
        $section->addTextBreak(1);
    }

    protected function tulisJudul(Section $section, array $data): void
    {
        $section->addText('LAPORAN KEHADIRAN PEGAWAI', [
            'bold' => true, 'size' => 13, 'color' => self::WARNA_NAVY,
        ], ['alignment' => Jc::CENTER, 'spaceBefore' => 200, 'spaceAfter' => 100]);

        $rincian = $section->addTextRun(['alignment' => Jc::CENTER, 'spaceAfter' => 300]);
        $rincian->addText('Periode: ', ['size' => 10]);
        $rincian->addText($data['periode_label'], ['size' => 10, 'bold' => true]);
        $rincian->addText('   \\   Unit Kerja: ', ['size' => 10]);
        $rincian->addText($data['cakupan'], ['size' => 10, 'bold' => true]);
    }

    protected function tulisRingkasan(Section $section, array $data): void
    {
        $this->judulBagian($section, 'Ringkasan Data');

        $gaya = [
            'borderSize' => 6, 'borderColor' => 'CBD5E1', 'cellMargin' => 80,
            'width' => 100 * 50, 'unit' => 'pct',
        ];
        $gayaSel = ['valign' => 'center'];
        $gayaHeader = ['bold' => true, 'size' => 8, 'color' => '475569'];
        $gayaHeaderSel = array_merge($gayaSel, ['bgColor' => 'F1F5F9']);

        $tabel = $section->addTable($gaya);

        $tabel->addRow();
        foreach (['Unit Kerja', 'Pegawai', 'Hadir', 'Tepat', 'Terlambat', 'Tanpa Ket.', 'Kehadiran'] as $i => $judul) {
            $tabel->addCell($i === 0 ? 3200 : 900, $gayaHeaderSel)
                ->addText(mb_strtoupper($judul), $gayaHeader, ['alignment' => Jc::END]);
        }

        foreach ($data['per_unit'] as $unit) {
            $tabel->addRow();
            $tabel->addCell(3200, $gayaSel)->addText($unit['nama'], ['size' => 9]);
            $this->selAngka($tabel, (string) $unit['pegawai']);
            $this->selAngka($tabel, (string) $unit['hadir']);
            $this->selAngka($tabel, (string) $unit['tepat'], ['color' => self::WARNA_TEAL, 'bold' => true]);
            $this->selAngka($tabel, (string) $unit['terlambat'], $unit['terlambat'] > 0
                ? ['color' => self::WARNA_AMBER, 'bold' => true]
                : ['color' => self::WARNA_REDUP]);
            $this->selAngka($tabel, (string) $unit['tanpa_keterangan']);
            $this->selAngka($tabel, LaporanResmiService::formatPersen($unit['tingkat_kehadiran']).'%');
        }

        if ($data['per_unit'] === []) {
            $tabel->addRow();
            $tabel->addCell(9000, array_merge($gayaSel, ['gridSpan' => 7]))
                ->addText('Tidak ada data pada cakupan dan periode ini.', ['size' => 9, 'italic' => true, 'color' => self::WARNA_REDUP], ['alignment' => Jc::CENTER]);
        } else {
            $total = $data['total'];
            $tabel->addRow();
            $gayaTotal = array_merge($gayaHeaderSel, ['bgColor' => 'F1F5F9']);
            $tabel->addCell(3200, $gayaTotal)->addText('Total', ['bold' => true, 'size' => 9]);
            foreach ([$total['pegawai'], $total['hadir'], $total['tepat'], $total['terlambat'], $total['tanpa_keterangan']] as $nilai) {
                $tabel->addCell(900, $gayaTotal)->addText((string) $nilai, ['bold' => true, 'size' => 9], ['alignment' => Jc::END]);
            }
            $tabel->addCell(900, $gayaTotal)
                ->addText(LaporanResmiService::formatPersen($total['tingkat_kehadiran']).'%', ['bold' => true, 'size' => 9], ['alignment' => Jc::END]);
        }

        $section->addTextBreak(1);
    }

    /**
     * Lampiran rincian kehadiran: satu baris per pegawai per sesi absen,
     * lengkap dengan jam masuk dan jam pulang yang tercatat.
     *
     * Ditulis SETELAH pengesahan dan diawali pemutus halaman: yang
     * ditandatangani adalah ringkasan di atas, dan lampiran adalah bukti
     * pendukungnya — bukan sebaliknya.
     *
     * @param  array<string, mixed>  $data
     */
    protected function tulisLampiranRincian(Section $section, array $data): void
    {
        if (count($data['rincian']) === 0) {
            return;
        }

        $section->addPageBreak();
        $this->judulBagian($section, 'Lampiran — Rincian Kehadiran per Pegawai');

        $section->addText(
            sprintf(
                'Jam masuk dan jam pulang sebagaimana tercatat sistem pada periode %s. '
                .'Pegawai yang tidak memiliki catatan kehadiran tidak muncul pada lampiran '
                .'ini; jumlah ketidakhadirannya terbaca pada tabel Ringkasan Data di atas.',
                $data['periode_label'],
            ),
            ['size' => 9, 'color' => self::WARNA_REDUP],
            ['alignment' => Jc::BOTH, 'spaceAfter' => 160],
        );

        $gayaSel = ['valign' => 'center'];
        $gayaHeader = ['bold' => true, 'size' => 7.5, 'color' => '475569'];
        $gayaHeaderSel = array_merge($gayaSel, ['bgColor' => 'F1F5F9']);

        $tabel = $section->addTable([
            'borderSize' => 6, 'borderColor' => 'CBD5E1', 'cellMargin' => 60,
            'width' => 100 * 50, 'unit' => 'pct',
        ]);

        // Lebar per kolom, dalam twip; jumlahnya menentukan proporsi tabel.
        $lebar = [1900, 2200, 2000, 1000, 2000, 800, 800, 1000];
        $judul = ['NIP', 'Nama', 'Unit Kerja', 'Tanggal', 'Kegiatan', 'Masuk', 'Pulang', 'Status'];

        $tabel->addRow(null, ['tblHeader' => true]);

        foreach ($judul as $i => $teks) {
            $tabel->addCell($lebar[$i], $gayaHeaderSel)->addText(
                mb_strtoupper($teks),
                $gayaHeader,
                ['alignment' => $i >= 5 ? Jc::END : Jc::START],
            );
        }

        foreach ($data['rincian'] as $isi) {
            $tabel->addRow();

            $kolom = [
                $isi['nip'],
                $isi['nama'],
                $isi['unit_kerja'] ?? '—',
                $isi['tanggal_label'] ?? '—',
                $isi['kegiatan'] ?? '—',
                $isi['jam_masuk'] ?? '—',
                $isi['jam_pulang'] ?? '—',
                $isi['status_label'] ?? '—',
            ];

            foreach ($kolom as $i => $teks) {
                $tabel->addCell($lebar[$i], $gayaSel)->addText(
                    (string) $teks,
                    ['size' => 8],
                    ['alignment' => $i >= 5 ? Jc::END : Jc::START],
                );
            }
        }

        if ($data['rincian_dipotong'] > 0) {
            $section->addTextBreak(1);
            $section->addText(
                sprintf(
                    '%s baris berikutnya tidak dimuat agar dokumen tetap dapat dirakit. '
                    .'Gunakan "Unduh Data" pada menu Laporan untuk memperoleh seluruh baris '
                    .'sebagai CSV atau Excel.',
                    number_format($data['rincian_dipotong'], 0, ',', '.'),
                ),
                ['size' => 9, 'italic' => true, 'color' => self::WARNA_REDUP],
                ['alignment' => Jc::BOTH],
            );
        }

        $section->addTextBreak(1);
    }

    protected function selAngka($tabel, string $teks, array $gayaFont = []): void
    {
        $tabel->addCell(900, ['valign' => 'center'])
            ->addText($teks, array_merge(['size' => 9], $gayaFont), ['alignment' => Jc::END]);
    }

    protected function tulisKesimpulan(Section $section, array $data): void
    {
        $this->judulBagian($section, 'Kesimpulan');
        $section->addText($data['kesimpulan'], ['size' => 10], ['alignment' => Jc::BOTH, 'spaceAfter' => 200]);
    }

    protected function tulisRekomendasi(Section $section, array $data): void
    {
        $this->judulBagian($section, 'Rekomendasi');

        $rekomendasi = $data['rekomendasi'];

        if ($rekomendasi['tidak_ada_masalah']) {
            $section->addText(
                'Seluruh unit kerja menunjukkan tingkat kehadiran yang baik pada periode ini. '
                .'Tidak terdapat rekomendasi tindak lanjut khusus.',
                ['size' => 10, 'color' => '047857'],
                ['alignment' => Jc::BOTH, 'spaceAfter' => 200, 'shading' => ['fill' => 'ECFDF5']],
            );

            return;
        }

        if ($rekomendasi['kehadiran_rendah'] !== []) {
            $section->addText('a. Tingkat Kehadiran di Bawah Ambang Batas', [
                'bold' => true, 'size' => 9.5, 'color' => '334155',
            ], ['spaceBefore' => 100, 'spaceAfter' => 60]);

            foreach ($rekomendasi['kehadiran_rendah'] as $r) {
                $section->addListItem($r['kalimat'], 0, ['size' => 10], null, ['alignment' => Jc::BOTH, 'spaceAfter' => 60]);
            }
        }

        if ($rekomendasi['keterlambatan_tinggi'] !== []) {
            $section->addText('b. Tingkat Keterlambatan di Atas Ambang Batas', [
                'bold' => true, 'size' => 9.5, 'color' => '334155',
            ], ['spaceBefore' => 150, 'spaceAfter' => 60]);

            foreach ($rekomendasi['keterlambatan_tinggi'] as $r) {
                $section->addListItem($r['kalimat'], 0, ['size' => 10], null, ['alignment' => Jc::BOTH, 'spaceAfter' => 60]);
            }
        }
    }

    /**
     * Ruang pengesahan — dikosongkan, admin isi manual (keputusan pemilik
     * sistem: belum ada konvensi baku siapa yang menandatangani laporan ini).
     */
    protected function tulisPengesahan(Section $section, array $data): void
    {
        $section->addTextBreak(3);

        $section->addText('Surabaya, '.Carbon::now()->translatedFormat('d F Y'), ['size' => 10], [
            'alignment' => Jc::END, 'indentation' => ['left' => 5000],
        ]);
        $section->addTextBreak(4);
        $section->addText('( ___________________________ )', ['size' => 10], [
            'alignment' => Jc::END, 'indentation' => ['left' => 5000],
        ]);
        $section->addText('NIP. ', ['size' => 9, 'color' => self::WARNA_REDUP], [
            'alignment' => Jc::END, 'indentation' => ['left' => 5000],
        ]);
    }

    protected function tulisKaki(Section $section, array $data): void
    {
        $section->addTextBreak(2);
        $section->addText(
            sprintf('Dicetak %s oleh %s · Capture — Sistem Absensi Kegiatan', $data['dicetak'], $data['oleh']),
            ['size' => 7, 'color' => 'CBD5E1'],
            ['borderTopSize' => 4, 'borderTopColor' => 'E2E8F0', 'spaceBefore' => 100],
        );
    }

    protected function judulBagian(Section $section, string $judul): void
    {
        $section->addText(mb_strtoupper($judul), [
            'bold' => true, 'size' => 10, 'color' => self::WARNA_NAVY,
        ], [
            'borderBottomSize' => 6, 'borderBottomColor' => 'CBD5E1',
            'spaceBefore' => 150, 'spaceAfter' => 120,
        ]);
    }
}
