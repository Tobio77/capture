<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Kegagalan saat memanggil API WORKA.
 *
 * Membawa kode galat WORKA yang dapat dibaca mesin (mis. PEGAWAI_NOT_FOUND,
 * NIP_NOT_FOUND, PEGAWAI_TIDAK_AKTIF) agar pemanggil dapat membedakan
 * penyebabnya tanpa mencocokkan teks pesan yang sewaktu-waktu diperbaiki.
 *
 * Setiap instans langsung dicatat ke kanal log `worka`.
 */
class WorkaApiException extends RuntimeException
{
    public function __construct(
        string $pesan,
        public readonly ?int $httpStatus = null,
        public readonly ?string $workaCode = null,
        public readonly ?string $workaMessage = null,
        public readonly ?string $endpoint = null,
        ?Throwable $sebelumnya = null,
    ) {
        parent::__construct($pesan, $httpStatus ?? 0, $sebelumnya);

        $this->catat();
    }

    public static function dariKoneksi(string $endpoint, Throwable $sebelumnya): self
    {
        return new self(
            'Tidak dapat menghubungi WORKA. Periksa alamat API dan koneksi jaringan.',
            httpStatus: null,
            workaCode: 'KONEKSI_GAGAL',
            workaMessage: $sebelumnya->getMessage(),
            endpoint: $endpoint,
            sebelumnya: $sebelumnya,
        );
    }

    public static function tanpaToken(): self
    {
        return new self(
            'Token API WORKA belum dikonfigurasi. Isi melalui menu Setting → Integrasi WORKA.',
            workaCode: 'TOKEN_KOSONG',
        );
    }

    /**
     * Pesan siap tampil untuk admin, disesuaikan dengan penyebabnya.
     */
    public function pesanUntukAdmin(): string
    {
        return match (true) {
            $this->workaCode === 'TOKEN_KOSONG' => $this->getMessage(),
            $this->workaCode === 'KONEKSI_GAGAL' => $this->getMessage(),
            $this->httpStatus === 401 => 'Token API WORKA ditolak. Pastikan token masih berlaku.',
            $this->httpStatus === 403 => 'Token API WORKA tidak memiliki ability yang dibutuhkan '
                .'(absen:sync-pegawai, absen:read-pegawai, absen:read-unit, absen:read-foto).',
            $this->httpStatus === 429 => 'Permintaan ke WORKA melebihi batas laju. Coba lagi beberapa saat lagi.',
            $this->httpStatus !== null && $this->httpStatus >= 500 => 'WORKA sedang bermasalah (galat '
                .$this->httpStatus.'). Coba lagi nanti.',
            default => $this->workaMessage ?? $this->getMessage(),
        };
    }

    protected function catat(): void
    {
        Log::channel('worka')->error($this->getMessage(), [
            'endpoint' => $this->endpoint,
            'http_status' => $this->httpStatus,
            'worka_code' => $this->workaCode,
            'worka_message' => $this->workaMessage,
        ]);
    }
}
