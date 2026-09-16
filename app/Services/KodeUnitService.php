<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Kode perangkat per unit kerja — tanda pengenal unit di mata aplikasi
 * (FR-EVT-03, revisi S49).
 *
 * Satu kode per unit kerja level teratas, tetap sepanjang waktu. Sebuah
 * komputer yang hendak dipakai mengabsen mengetikkannya SEKALI; sejak itu
 * aplikasi mengenalinya sebagai perangkat milik unit tersebut, dan alamat
 * IP-nya ikut tercatat pada setiap absensi yang dilayaninya.
 *
 * Kode ini menggantikan dua mekanisme sekaligus yang sebelumnya berdiri
 * sendiri-sendiri:
 *
 *   - Kode aktivasi sekali pakai per perangkat (S04). Jalur itu masih ada,
 *     tetapi kini menjadi mode pendaftaran yang dimatikan secara bawaan —
 *     lihat {@see SettingAbsenService::pendaftaranPerangkatAktif()}.
 *   - Kode unit kerja per event (S29). Jalur itu dihapus seluruhnya: event
 *     kini selalu mencakup seluruh dinas, sehingga tidak ada lagi yang perlu
 *     dibedakan per kegiatan — perangkat yang sudah dikenali langsung
 *     melayani event yang sedang berjalan.
 *
 * Jumlah perangkat per unit tidak dibatasi. Satu unit boleh memakai tiga
 * komputer hari Rabu dan empat hari Kamis; masing-masing memperoleh barisnya
 * sendiri pada Daftar Perangkat, dan rekap absensinya menyebut perangkat mana
 * beserta alamat IP-nya.
 */
class KodeUnitService
{
    /** Panjang kode, mengikuti kode aktivasi perangkat (S04). */
    public const int PANJANG_KODE = 8;

    /**
     * Abjad tanpa karakter yang mudah tertukar saat dibacakan: 0/O dan 1/I
     * dibuang, sama seperti keputusan S04 pada kode aktivasi perangkat.
     */
    protected const string ABJAD = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function __construct(protected LogAktivitasService $log) {}

    /**
     * Unit kerja pemilik sebuah kode, atau null bila kodenya tidak dikenal.
     *
     * Unit yang sedang dinonaktifkan sengaja ikut ditolak: menonaktifkan unit
     * berarti ia tidak lagi menyelenggarakan absensi, dan perangkat baru yang
     * masuk atas namanya hanya akan menghasilkan absensi yang tidak diakui
     * rekap mana pun.
     */
    public function unitDariKode(string $kode): ?UnitKerja
    {
        $bersih = self::normalkan($kode);

        if ($bersih === '') {
            return null;
        }

        return UnitKerja::query()
            ->aktif()
            ->where('kode_perangkat', $bersih)
            ->first();
    }

    /**
     * Pastikan sebuah unit punya kode; terbitkan bila belum.
     *
     * Dipanggil ketika unit kerja baru dibuat admin maupun lahir dari
     * sinkronisasi WORKA — tanpa ini, unit yang menyusul setelah rilis tidak
     * akan pernah punya kode dan perangkatnya tidak dapat masuk sama sekali.
     */
    public function pastikanAda(UnitKerja $unit): UnitKerja
    {
        if ($unit->kode_perangkat !== null) {
            return $unit;
        }

        $unit->forceFill(['kode_perangkat' => $this->kodeAcak()])->save();

        return $unit;
    }

    /**
     * Ganti kode sebuah unit kerja (FR-EVT-03).
     *
     * Perangkat yang SUDAH dikenali tidak terputus: ia memegang device token
     * sendiri, dan kode hanyalah cara memperolehnya. Reset menutup pintu bagi
     * mesin yang belum masuk — yaitu keadaan yang dituju ketika kode telanjur
     * tersebar ke luar unit. Untuk memutus perangkat tertentu, cabut aksesnya
     * lewat Kelola Perangkat Absen (FR-USR-03).
     */
    public function reset(UnitKerja $unit, User $pelaku): UnitKerja
    {
        $unit->forceFill([
            'kode_perangkat' => $this->kodeAcak(),
            'kode_perangkat_direset_oleh' => $pelaku->id,
            'kode_perangkat_direset_pada' => Carbon::now(),
        ])->save();

        $this->log->catat(
            AksiLog::Ubah,
            "Mengganti kode perangkat unit kerja {$unit->kode} — {$unit->nama}. ".
            'Perangkat yang sudah dikenali tidak terputus.',
            user: $pelaku,
            subjek: $unit,
        );

        return $unit;
    }

    /**
     * Kode acak yang belum dipakai unit mana pun.
     */
    protected function kodeAcak(): string
    {
        do {
            $kode = '';

            for ($i = 0; $i < self::PANJANG_KODE; $i++) {
                $kode .= self::ABJAD[random_int(0, strlen(self::ABJAD) - 1)];
            }
        } while (UnitKerja::query()->where('kode_perangkat', $kode)->exists());

        return $kode;
    }

    /** Bentuk yang ditampilkan ke admin dan dibacakan kepada petugas. */
    public static function format(?string $kode): ?string
    {
        return $kode === null || $kode === '' ? null : implode('-', str_split($kode, 4));
    }

    /** Terima ketikan apa adanya: spasi, tanda hubung, dan huruf kecil. */
    public static function normalkan(string $kode): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $kode) ?? '');
    }
}
