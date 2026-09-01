<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Models\Pegawai;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Pendaftaran dan pembaruan foto referensi wajah pegawai (FR-PEG-05).
 *
 * Foto disimpan pada disk privat, tidak pernah pada disk publik: NFR-04
 * melarang berkas foto diakses tanpa autentikasi. Yang dikirim ke kiosk hanya
 * embedding 128 dimensi, bukan fotonya (SDD §3).
 *
 * Embedding dihitung di browser saat pendaftaran memakai face-api.js —
 * konsisten dengan keputusan arsitektur bahwa tidak ada pemrosesan wajah di
 * sisi server. Service ini hanya menerima, memvalidasi bentuknya, dan
 * menyimpan.
 */
class FotoReferensiWajahService
{
    /** Disk privat tempat foto referensi disimpan. */
    public const string DISK = 'local';

    public const string DIREKTORI = 'foto-referensi';

    /** Panjang deskriptor wajah face-api.js. */
    public const int DIMENSI_EMBEDDING = 128;

    public function __construct(protected LogAktivitasService $log) {}

    /**
     * Simpan foto referensi beserta embedding-nya, menggantikan yang lama.
     *
     * @param  array<int, float>  $embedding
     */
    public function daftarkan(Pegawai $pegawai, UploadedFile $foto, array $embedding, User $pelaku): Pegawai
    {
        $pembaruan = $pegawai->wajah_terdaftar;
        $pathLama = $pegawai->foto_referensi_path;

        $path = $foto->storeAs(
            self::DIREKTORI,
            $this->namaBerkas($pegawai, $foto),
            ['disk' => self::DISK],
        );

        $pegawai->update([
            'foto_referensi_path' => $path,
            'embedding_wajah' => array_map(fn ($nilai) => (float) $nilai, $embedding),
            'wajah_terdaftar' => true,
            'wajah_didaftarkan_at' => Carbon::now(),
        ]);

        // Berkas lama dihapus setelah baris tersimpan, supaya kegagalan
        // penyimpanan tidak meninggalkan pegawai tanpa foto sama sekali.
        $this->hapusBerkas($pathLama, kecuali: $path);

        $this->log->catat(
            AksiLog::Ubah,
            ($pembaruan ? 'Memperbarui' : 'Mendaftarkan')
                ." foto referensi wajah {$pegawai->nip} — {$pegawai->nama}.",
            user: $pelaku,
            subjek: $pegawai,
        );

        return $pegawai;
    }

    /**
     * Cabut pendaftaran wajah: berkas dan embedding dihapus, baris pegawai
     * tetap ada karena datanya milik WORKA.
     */
    public function cabut(Pegawai $pegawai, User $pelaku): Pegawai
    {
        $pathLama = $pegawai->foto_referensi_path;

        $pegawai->update([
            'foto_referensi_path' => null,
            'embedding_wajah' => null,
            'wajah_terdaftar' => false,
            'wajah_didaftarkan_at' => null,
        ]);

        $this->hapusBerkas($pathLama);

        $this->log->catat(
            AksiLog::Hapus,
            "Mencabut pendaftaran wajah {$pegawai->nip} — {$pegawai->nama}.",
            user: $pelaku,
            subjek: $pegawai,
        );

        return $pegawai;
    }

    /**
     * Apakah nilai yang dikirim klien berbentuk deskriptor wajah yang sah.
     *
     * @param  mixed  $embedding
     */
    public static function embeddingSah($embedding): bool
    {
        if (! is_array($embedding) || count($embedding) !== self::DIMENSI_EMBEDDING) {
            return false;
        }

        foreach ($embedding as $nilai) {
            if (! is_numeric($nilai) || ! is_finite((float) $nilai)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Nama berkas memakai NIP agar satu pegawai selalu menempati satu berkas,
     * ditambah stempel waktu supaya pembaruan tidak tertahan cache peramban.
     */
    protected function namaBerkas(Pegawai $pegawai, UploadedFile $foto): string
    {
        $ekstensi = $foto->extension() ?: 'jpg';

        return "{$pegawai->nip}-".Carbon::now()->format('YmdHis').".{$ekstensi}";
    }

    protected function hapusBerkas(?string $path, ?string $kecuali = null): void
    {
        if ($path === null || $path === '' || $path === $kecuali) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }
}
