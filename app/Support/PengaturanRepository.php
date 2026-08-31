<?php

namespace App\Support;

use App\Models\Pengaturan;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Baca/tulis pengaturan runtime.
 *
 * Seluruh pembacaan dibungkus try/catch: perintah seperti `migrate` pada
 * basis data kosong tetap harus berjalan walaupun tabel `pengaturan`
 * belum ada.
 */
class PengaturanRepository
{
    /** @var array<string, string|null> */
    protected array $ingatan = [];

    public function ambil(string $kunci, ?string $bawaan = null): ?string
    {
        if (array_key_exists($kunci, $this->ingatan)) {
            return $this->ingatan[$kunci] ?? $bawaan;
        }

        try {
            $baris = Pengaturan::query()->where('kunci', $kunci)->first();
        } catch (Throwable) {
            return $bawaan;
        }

        if ($baris === null || $baris->nilai === null) {
            $this->ingatan[$kunci] = null;

            return $bawaan;
        }

        $nilai = $baris->terenkripsi ? $this->buka($baris->nilai) : $baris->nilai;

        $this->ingatan[$kunci] = $nilai;

        return $nilai ?? $bawaan;
    }

    public function simpan(string $kunci, ?string $nilai, bool $terenkripsi = false): void
    {
        Pengaturan::query()->updateOrCreate(
            ['kunci' => $kunci],
            [
                'nilai' => $nilai === null || $nilai === ''
                    ? null
                    : ($terenkripsi ? Crypt::encryptString($nilai) : $nilai),
                'terenkripsi' => $terenkripsi,
            ],
        );

        $this->ingatan[$kunci] = $nilai === '' ? null : $nilai;
    }

    /**
     * @param  array<string, string|null>  $nilai
     */
    public function simpanBanyak(array $nilai, bool $terenkripsi = false): void
    {
        foreach ($nilai as $kunci => $isi) {
            $this->simpan($kunci, $isi, $terenkripsi);
        }
    }

    public function lupakan(string $kunci): void
    {
        unset($this->ingatan[$kunci]);
    }

    public function bersihkanIngatan(): void
    {
        $this->ingatan = [];
    }

    protected function buka(string $nilai): ?string
    {
        try {
            return Crypt::decryptString($nilai);
        } catch (DecryptException) {
            // Kunci APP_KEY berganti setelah nilai tersimpan — perlakukan
            // seakan belum diatur, bukan menjatuhkan seluruh permintaan.
            return null;
        }
    }
}
