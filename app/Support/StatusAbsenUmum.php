<?php

namespace App\Support;

use App\Enums\JenisAbsen;
use App\Enums\OverrideAbsenUmum;

/**
 * Status efektif Absen Umum untuk satu jenis absen pada satu saat (FR-SET-07).
 *
 * Statusnya dirakit dari tiga sumber, dan urutan resolusinya tetap:
 *
 *   1. Setting Absen mematikan absen umum sama sekali → tertutup, titik.
 *   2. Ada override manual admin → override menang, apa pun kata jadwal.
 *   3. Selebihnya → mengikuti jendela jam bawaan untuk jenis itu.
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
         * Ia MENANDAI, tidak menutup: absen tetap diterima. Petugas piket akhir
         * pekan yang benar-benar masuk harus tetap dapat mencatat kehadirannya,
         * dan yang dibutuhkan rekap hanyalah tahu bahwa tap itu jatuh di luar
         * hari kerja.
         */
        public ?string $alasanLibur = null,
    ) {}

    /**
     * Kalimat siap tampil yang menerangkan MENGAPA statusnya begini.
     *
     * Sengaja menyebut sumbernya, bukan hanya keadaannya: "Tertutup" saja
     * membuat admin memeriksa jam kantor, padahal penyebabnya bisa saja
     * override yang tertinggal.
     */
    public function keterangan(): string
    {
        $jendela = "{$this->jamBuka}–{$this->jamTutup}";

        /*
         * Keterangan hari libur ditempelkan pada keterangan yang sudah ada,
         * bukan menggantikannya: statusnya tetap ditentukan jendela jam, dan
         * yang ditambahkan hanyalah kabar bahwa tap hari ini akan ditandai.
         */
        $libur = $this->alasanLibur === null ? '' : " {$this->alasanLibur}; tap tetap diterima dan ditandai hari libur.";

        return match ($this->sumber) {
            'setting' => 'Absen umum sedang dimatikan pada Setting Absen.',
            'override' => sprintf(
                '%s oleh %s — mengabaikan jadwal %s, berlaku hari ini saja.',
                $this->override?->label() ?? 'Diubah manual',
                $this->olehNama ?? 'admin',
                $jendela,
            ),
            default => $this->terbuka
                ? "Terbuka mengikuti jadwal {$jendela}.{$libur}"
                : "Di luar jadwal {$jendela}.{$libur}",
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
