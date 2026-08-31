<?php

namespace App\Support;

/**
 * Ringkasan hasil satu kali sinkronisasi pegawai dari WORKA.
 */
readonly class SinkronResult
{
    public function __construct(
        public string $status,
        public int $ditambahkan = 0,
        public int $diperbarui = 0,
        public int $dinonaktifkan = 0,
        public int $unitKerjaDiperbarui = 0,
        public float $durasiDetik = 0.0,
        public string $pesan = '',
        public ?string $error = null,
    ) {}

    public const string SUKSES = 'sukses';

    public const string TIDAK_ADA_PERUBAHAN = 'tidak_ada_perubahan';

    public const string GAGAL = 'gagal';

    public static function sukses(
        int $ditambahkan,
        int $diperbarui,
        int $dinonaktifkan,
        int $unitKerjaDiperbarui,
        float $durasiDetik,
    ): self {
        return new self(
            status: self::SUKSES,
            ditambahkan: $ditambahkan,
            diperbarui: $diperbarui,
            dinonaktifkan: $dinonaktifkan,
            unitKerjaDiperbarui: $unitKerjaDiperbarui,
            durasiDetik: $durasiDetik,
            pesan: sprintf(
                'Sinkronisasi selesai: %d ditambahkan, %d diperbarui, %d dinonaktifkan.',
                $ditambahkan,
                $diperbarui,
                $dinonaktifkan,
            ),
        );
    }

    public static function tidakAdaPerubahan(float $durasiDetik = 0.0): self
    {
        return new self(
            status: self::TIDAK_ADA_PERUBAHAN,
            durasiDetik: $durasiDetik,
            pesan: 'Data pegawai di WORKA tidak berubah sejak sinkronisasi terakhir.',
        );
    }

    public static function gagal(string $error, float $durasiDetik = 0.0): self
    {
        return new self(
            status: self::GAGAL,
            durasiDetik: $durasiDetik,
            pesan: 'Sinkronisasi gagal.',
            error: $error,
        );
    }

    public function berhasil(): bool
    {
        return $this->status !== self::GAGAL;
    }

    public function adaPerubahan(): bool
    {
        return $this->ditambahkan > 0 || $this->diperbarui > 0 || $this->dinonaktifkan > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'ditambahkan' => $this->ditambahkan,
            'diperbarui' => $this->diperbarui,
            'dinonaktifkan' => $this->dinonaktifkan,
            'unit_kerja_diperbarui' => $this->unitKerjaDiperbarui,
            'durasi_detik' => round($this->durasiDetik, 2),
            'pesan' => $this->pesan,
            'error' => $this->error,
        ];
    }
}
