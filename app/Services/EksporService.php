<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Penyusunan berkas unduhan CSV dan PDF.
 *
 * Dipusatkan di sini supaya bentuk berkasnya seragam di seluruh menu — dan
 * supaya keputusan yang mudah terlewat, seperti pemisah CSV dan BOM, hanya
 * ditetapkan satu kali.
 */
class EksporService
{
    /**
     * Susun CSV siap dibuka Excel.
     *
     * Pemisahnya titik koma, bukan koma: Excel berlokal Indonesia membaca koma
     * sebagai pemisah desimal dan akan menggabungkan seluruh kolom menjadi
     * satu. BOM UTF-8 disertakan supaya nama ber-diakritik tidak rusak.
     *
     * @param  array<int, string>  $judul
     * @param  Collection<int, array<int, mixed>>  $baris
     */
    public function csv(array $judul, Collection $baris): string
    {
        $garis = [$this->barisCsv($judul)];

        foreach ($baris as $isi) {
            $garis[] = $this->barisCsv($isi);
        }

        return "\u{FEFF}".implode("\r\n", $garis)."\r\n";
    }

    /**
     * @param  array<int, mixed>  $kolom
     */
    protected function barisCsv(array $kolom): string
    {
        return implode(';', array_map(
            fn ($nilai) => '"'.str_replace('"', '""', (string) $nilai).'"',
            $kolom,
        ));
    }

    /**
     * Kolom yang diminta klien lewat checklist ("Unduh Data"), disaring ke
     * daftar yang dikenal ($kolomTersedia) dan disusun ulang ke urutan
     * kanonisnya — bukan urutan kiriman klien, supaya berkas yang dihasilkan
     * tetap dapat diprediksi. Dipakai bersama oleh Laporan dan Rekap (kedua
     * tab): bentuknya sama meski daftar kolomnya berbeda-beda per menu.
     *
     * @param  array<string, string>  $kolomTersedia  peta kunci => label, urutan kanonis
     * @param  array<int, string>  $wajib  kunci yang selalu ikut apa pun yang diminta
     * @return array<int, string>
     */
    public function kolomAktif(Request $request, array $kolomTersedia, array $wajib = []): array
    {
        $diminta = $request->has('kolom')
            ? array_merge($wajib, (array) $request->input('kolom'))
            : array_keys($kolomTersedia);

        return array_values(array_intersect(array_keys($kolomTersedia), $diminta));
    }

    public function unduhCsv(string $isi, string $namaBerkas): StreamedResponse
    {
        return response()->streamDownload(
            fn () => print $isi,
            $namaBerkas,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * Render lembar cetak menjadi PDF.
     *
     * Lanskap dipilih untuk tabel lebar (laporan, rekap, daftar event): potret
     * memaksa kolom saling berdesakan sampai tidak terbaca.
     *
     * @param  array<string, mixed>  $data
     */
    public function unduhPdf(
        string $tampilan,
        array $data,
        string $namaBerkas,
        string $orientasi = 'landscape',
    ): Response {
        return Pdf::loadView($tampilan, $data + $this->jejakCetak())
            ->setPaper('a4', $orientasi)
            ->download($namaBerkas);
    }

    /**
     * Jejak siapa mencetak dan kapan — lembar rekap dan laporan dipakai
     * sebagai lampiran administratif, sehingga asal-usulnya perlu terbaca.
     *
     * @return array<string, string>
     */
    protected function jejakCetak(): array
    {
        return [
            'dicetak' => Carbon::now()->translatedFormat('d F Y H:i'),
            'oleh' => auth()->user()?->nama ?? 'sistem',
        ];
    }
}
