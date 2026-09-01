<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Models\Pegawai;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Pendaftaran kartu RFID pegawai (FR-TAP-03).
 *
 * Reader 13,56 MHz kelas USB/HID mengeluarkan UID kartu sebagai ketikan
 * keyboard. Bentuk keluarannya berbeda-beda antar merek — desimal, heksa,
 * kadang dipisah titik dua atau tanda hubung — sehingga seluruh nilai
 * dinormalkan sebelum disimpan maupun dicocokkan.
 */
class KartuRfidService
{
    public function __construct(protected LogAktivitasService $log) {}

    /**
     * Normalkan keluaran reader: buang pemisah, samakan menjadi huruf kapital.
     *
     * Kartu yang sama harus menghasilkan nilai yang sama walau dibaca reader
     * bermerek lain yang memakai gaya penulisan berbeda.
     */
    public static function normalkan(string $nilai): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $nilai) ?? '');
    }

    /**
     * Tautkan sebuah kartu ke pegawai, menggantikan kartu lamanya bila ada.
     */
    public function daftarkan(Pegawai $pegawai, string $uid, User $pelaku): Pegawai
    {
        $uid = self::normalkan($uid);
        $penggantian = $pegawai->uid_kartu !== null;

        $pegawai->update([
            'uid_kartu' => $uid,
            'kartu_didaftarkan_at' => Carbon::now(),
        ]);

        $this->log->catat(
            AksiLog::Ubah,
            ($penggantian ? 'Mengganti' : 'Mendaftarkan')
                ." kartu RFID {$pegawai->nip} — {$pegawai->nama}.",
            user: $pelaku,
            subjek: $pegawai,
        );

        return $pegawai;
    }

    /**
     * Cabut kartu, misalnya karena hilang. Pegawai tetap dapat absen manual.
     */
    public function cabut(Pegawai $pegawai, User $pelaku): Pegawai
    {
        $pegawai->update(['uid_kartu' => null, 'kartu_didaftarkan_at' => null]);

        $this->log->catat(
            AksiLog::Hapus,
            "Mencabut kartu RFID {$pegawai->nip} — {$pegawai->nama}.",
            user: $pelaku,
            subjek: $pegawai,
        );

        return $pegawai;
    }

    /**
     * Kenali pegawai dari apa pun yang masuk lewat kolom tap.
     *
     * Dicocokkan ke NIP lebih dulu, baru ke UID kartu: bila kartu di lokasi
     * ternyata sudah berisi NIP, tap langsung bekerja tanpa pendaftaran kartu.
     */
    public function kenali(string $masukan): ?Pegawai
    {
        $bersih = trim($masukan);

        $pegawai = Pegawai::query()
            ->with('unitKerja:id,kode,nama')
            ->where('nip', $bersih)
            ->first();

        if ($pegawai !== null) {
            return $pegawai;
        }

        $uid = self::normalkan($bersih);

        if ($uid === '') {
            return null;
        }

        return Pegawai::query()
            ->with('unitKerja:id,kode,nama')
            ->where('uid_kartu', $uid)
            ->first();
    }
}
