<?php

namespace App\Support;

use App\Enums\JenisAbsen;
use App\Enums\OverrideAbsenUmum;

/**
 * Status efektif Absen Umum untuk satu jenis absen pada satu saat (FR-SET-07).
 *
 * Statusnya dirakit dari empat sumber, dan urutan resolusinya tetap:
 *
 *   1. Setting Absen mematikan absen umum sama sekali → tertutup, titik.
 *   2. Ada override manual admin → override menang, apa pun kata kalender
 *      maupun jadwal.
 *   3. Bukan hari kerja (akhir pekan atau hari libur terdaftar) → tertutup
 *      otomatis. Revisi dari kebijakan S39 ("menandai, tidak menutup"): sejak
 *      audit kalender kerja, jendela default memang tertutup di luar hari
 *      kerja — satu-satunya jalan tetap menerima tap pada hari itu adalah
 *      override manual di langkah 2, yang sengaja diperiksa LEBIH DAHULU.
 *   4. Selebihnya → mengikuti jendela jam bawaan untuk jenis itu.
 *
 * Penanda `Absensi.hari_libur` tidak berubah maknanya: absen yang diterima
 * lewat override pada hari libur tetap tercatat dengan penanda itu, dan rekap
 * tetap membacanya sebagai "hadir di luar hari kerja", bukan hari kerja biasa.
 *
 * `sumber` ikut dibawa karena admin harus dapat membedakan "tertutup karena
 * memang di luar jam" dari "tertutup karena seseorang menutupnya kemarin dan
 * lupa mencabutnya" — dua keadaan yang terlihat sama persis di layar, tetapi
 * menuntut tindakan yang berbeda.
 */
final readonly class StatusAbsenUmum
{
    public function __construct(
        public JenisAbsen $jenis,
        public bool $terbuka,

        /** 'setting' | 'override' | 'jadwal' */
        public string $sumber,

        /** Jam buka jendela bawaan, HH:MM. */
        public string $jamBuka,

        /** Jam tutup jendela bawaan, HH:MM. */
        public string $jamTutup,

        public ?OverrideAbsenUmum $override = null,
        public ?string $olehNama = null,

        /**
         * Alasan hari ini bukan hari kerja, atau null bila ia hari kerja.
         *
         * Dibawa terpisah dari `sumber` karena keduanya menjawab pertanyaan
         * berbeda: `sumber` menjelaskan MENGAPA jendela tertutup/terbuka,
         * sedangkan ini menjelaskan APA hari ini — dan keduanya bisa berbeda,
         * misalnya saat override membuka paksa jendela pada hari libur
         * (`sumber` = 'override', tetapi `alasanLibur` tetap terisi supaya
         * pesannya tidak diam-diam menyembunyikan bahwa ini hari libur).
         */
        public ?string $alasanLibur = null,
    ) {}

    /**
     * Kalimat siap tampil yang menerangkan MENGAPA statusnya begini.
     *
     * Sengaja menyebut sumbernya, bukan hanya keadaannya: "Tertutup" saja
     * membuat admin memeriksa jam kantor, padahal penyebabnya bisa saja
     * override yang tertinggal — atau, sejak revisi kalender kerja, hari
     * libur yang belum diberi override.
     */
    public function keterangan(): string
    {
        $jendela = "{$this->jamBuka}–{$this->jamTutup}";

        return match ($this->sumber) {
            'setting' => 'Absen umum sedang dimatikan pada Setting Absen.',
            'override' => sprintf(
                '%s oleh %s — mengabaikan %s, berlaku hari ini saja.',
                $this->override?->label() ?? 'Diubah manual',
                $this->olehNama ?? 'admin',
                $this->alasanLibur === null
                    ? "jadwal {$jendela}"
                    : "kalender ({$this->alasanLibur}) maupun jadwal {$jendela}",
            ),

            /*
             * Alasan kalendernya SENDIRI menjadi kalimat utama — persis nama
             * hari libur yang diketik admin, atau nama harinya untuk akhir
             * pekan — bukan tempelan di ujung pesan jadwal seperti sebelum
             * revisi ini. "Entry ditutup" generik tidak pernah menjawab
             * pertanyaan petugas yang berdiri di depan layar: kenapa.
             */
            'kalender' => "{$this->alasanLibur}. Entri Absen Umum tertutup otomatis di luar hari kerja.",

            default => $this->terbuka
                ? "Terbuka mengikuti jadwal {$jendela}."
                : "Di luar jadwal {$jendela}.",
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function untukLayar(): array
    {
        return [
            'jenis' => $this->jenis->value,
            'alasan_libur' => $this->alasanLibur,
            'terbuka' => $this->terbuka,
            'sumber' => $this->sumber,
            'jam_buka' => $this->jamBuka,
            'jam_tutup' => $this->jamTutup,
            'override' => $this->override?->value,
            'oleh' => $this->olehNama,
            'keterangan' => $this->keterangan(),
        ];
    }
}
