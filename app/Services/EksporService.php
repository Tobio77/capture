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
            fn ($nilai) => '"'.str_replace('"', '""', (string) self::amankanFormula($nilai)).'"',
            $kolom,
        ));
    }

    /**
     * Netralkan nilai yang bisa dibaca Excel/LibreOffice sebagai AWAL FORMULA
     * ketika berkas CSV/XLSX dibuka (CWE-1236, "CSV/Formula Injection").
     *
     * Beberapa kolom yang diekspor berasal dari isian bebas admin — nama unit
     * kerja, catatan event, keterangan hari libur — termasuk Admin UPT yang
     * haknya lebih rendah daripada superadmin yang kelak membuka berkasnya.
     * Tanpa pagar ini, sebuah nilai seperti `=HYPERLINK("http://...","klik")`
     * akan dieksekusi Excel begitu berkasnya dibuka, bukan tertulis apa
     * adanya sebagai teks.
     *
     * Menulis sel bertipe STRING pada berkasnya (`setCellValueExplicit`)
     * TIDAK cukup: Excel menentukan sendiri apakah sebuah sel adalah formula
     * berdasarkan KARAKTER PERTAMANYA saat dibuka, bukan tipe sel yang
     * ditulis penulisnya. Satu-satunya pagar yang benar-benar berlaku di sisi
     * pembaca adalah membubuhkan apostrof di depan nilai yang diawali
     * `=`, `+`, `-`, `@`, atau tab — memaksa Excel membacanya sebagai teks.
     */
    public static function amankanFormula(mixed $nilai): mixed
    {
        if (! is_string($nilai) || $nilai === '') {
            return $nilai;
        }

        return preg_match('/^[=+\-@\t\r]/', $nilai) === 1 ? "'".$nilai : $nilai;
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
        return $this->denganMemoriBesar(fn () => Pdf::loadView($tampilan, $data + $this->jejakCetak())
            ->setPaper('a4', $orientasi)
            ->download($namaBerkas));
    }

    /**
     * Sama seperti {@see self::unduhPdf()}, tetapi ditampilkan LANGSUNG di
     * tab peramban (pratinjau), bukan diunduh sebagai berkas. Dipakai
     * tombol "Preview" Generate Laporan — melihat isinya dulu sebelum
     * benar-benar memprosesnya lewat Riwayat Laporan.
     */
    public function tampilkanPdf(
        string $tampilan,
        array $data,
        string $namaBerkas,
        string $orientasi = 'landscape',
    ): Response {
        return $this->denganMemoriBesar(fn () => Pdf::loadView($tampilan, $data + $this->jejakCetak())
            ->setPaper('a4', $orientasi)
            ->stream($namaBerkas));
    }

    /**
     * Render PDF menjadi BYTES mentah, bukan Response — dipakai
     * BuatLaporanResmiJob yang menyimpan hasilnya ke disk, bukan menjawab
     * permintaan HTTP secara langsung. Tidak membubuhkan jejakCetak()
     * sendiri: pemanggil dari job menyusun jejaknya sendiri dari pemohon
     * ASLI (`riwayat.user`), bukan `auth()->user()` yang tidak berarti apa-apa
     * di luar konteks permintaan HTTP.
     *
     * @param  array<string, mixed>  $data
     */
    public function bytesPdf(string $tampilan, array $data, string $orientasi = 'portrait'): string
    {
        return $this->denganMemoriBesar(fn () => Pdf::loadView($tampilan, $data)
            ->setPaper('a4', $orientasi)
            ->output());
    }

    /**
     * Batas memori PHP bawaan (128M) tidak cukup untuk merender tabel PDF
     * beberapa ratus baris lewat DomPDF — pustakanya membangun seluruh model
     * kotak CSS di memori sekaligus, bukan mengalirkannya per baris. Tanpa
     * pagar ini, admin yang meng-ekspor Laporan Kehadiran seluruh pegawai
     * (bukan skenario langka — itu justru kasus paling wajar) mendapati
     * proses unduhnya gagal total di tengah jalan tanpa pesan yang jelas:
     * PHP fatal error karena kehabisan memori, dan peramban hanya melihat
     * unduhan yang tidak pernah selesai.
     *
     * Dinaikkan lewat `ini_set()` (bukan php.ini global), dan SENGAJA TIDAK
     * dikembalikan sesudahnya: `memory_limit` adalah pengaturan per proses
     * PHP, dan aplikasi ini berjalan model PHP-FPM/`php artisan serve` biasa
     * (bukan Octane) — setiap permintaan mendapat proses baru dengan INI
     * bersih, jadi tidak ada permintaan LAIN yang ikut mewarisi batas yang
     * dilonggarkan ini. Mencoba mengembalikannya di akhir permintaan yang
     * SAMA justru gagal dengan peringatan: pada titik itu penggunaan memori
     * sudah melampaui batas lama (itulah sebabnya dinaikkan), dan PHP
     * menolak `ini_set()` yang menurunkan batas di bawah pemakaian berjalan.
     */
    protected function denganMemoriBesar(callable $render): mixed
    {
        ini_set('memory_limit', '512M');

        return $render();
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
