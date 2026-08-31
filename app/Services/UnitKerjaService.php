<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pengelolaan unit kerja peserta SI-ABSEN (FR-UNIT-01, FR-UNIT-02).
 */
class UnitKerjaService
{
    public function __construct(protected LogAktivitasService $log) {}

    /**
     * Daftar unit kerja beserta jumlah pegawai dan kiosk terdaftar (FR-UNIT-02).
     *
     * @return Collection<int, UnitKerja>
     */
    public function daftar(): Collection
    {
        return UnitKerja::withCount(['pegawai', 'kiosk'])
            ->orderBy('kode')
            ->get();
    }

    /**
     * @param  array{kode: string, nama: string}  $data
     */
    public function buat(array $data, User $pelaku): UnitKerja
    {
        $unitKerja = UnitKerja::create([
            'kode' => self::normalkanKode($data['kode']),
            'nama' => $data['nama'],
            'aktif' => true,
        ]);

        $this->log->catat(
            AksiLog::Buat,
            "Menambah unit kerja {$unitKerja->kode} — {$unitKerja->nama}.",
            user: $pelaku,
            subjek: $unitKerja,
        );

        return $unitKerja;
    }

    /**
     * @param  array{kode: string, nama: string}  $data
     */
    public function perbarui(UnitKerja $unitKerja, array $data, User $pelaku): UnitKerja
    {
        $sebelum = "{$unitKerja->kode} — {$unitKerja->nama}";

        $unitKerja->update([
            'kode' => self::normalkanKode($data['kode']),
            'nama' => $data['nama'],
        ]);

        $this->log->catat(
            AksiLog::Ubah,
            "Mengubah unit kerja {$sebelum} menjadi {$unitKerja->kode} — {$unitKerja->nama}.",
            user: $pelaku,
            subjek: $unitKerja,
        );

        return $unitKerja;
    }

    /**
     * Unit kerja tidak pernah dihapus, hanya dinonaktifkan, agar riwayat
     * absensi dan pegawai yang menautnya tetap utuh (FR-UNIT-01).
     */
    public function ubahStatus(UnitKerja $unitKerja, bool $aktif, User $pelaku): UnitKerja
    {
        $unitKerja->update(['aktif' => $aktif]);

        $this->log->catat(
            AksiLog::Ubah,
            ($aktif ? 'Mengaktifkan' : 'Menonaktifkan')." unit kerja {$unitKerja->kode} — {$unitKerja->nama}.",
            user: $pelaku,
            subjek: $unitKerja,
        );

        return $unitKerja;
    }

    public static function normalkanKode(string $kode): string
    {
        return Str::upper(trim($kode));
    }
}
