<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Enums\KompresiFoto;
use App\Models\User;
use App\Support\PengaturanRepository;

/**
 * Setting Absen — pengaturan global sistem (FR-SET-01 s.d. FR-SET-04).
 *
 * Nilainya disimpan pada tabel key-value `pengaturan`, mekanisme yang sama
 * dengan Integrasi WORKA, sesuai kelonggaran SDD §3.9 ("single-row atau
 * key-value"). Seluruh pembacaan selalu jatuh ke nilai bawaan bila kunci
 * belum pernah disimpan, sehingga sistem tetap berjalan pada instalasi baru
 * tanpa perlu seeding pengaturan.
 */
class SettingAbsenService
{
    public const string KUNCI_MANUAL = 'absen.metode_manual_aktif';

    public const string KUNCI_RFID = 'absen.metode_rfid_aktif';

    public const string KUNCI_WAJAH = 'absen.metode_wajah_aktif';

    public const string KUNCI_TOLERANSI = 'absen.toleransi_default_menit';

    public const string KUNCI_AMBANG_WAJAH = 'absen.ambang_kecocokan_wajah';

    public const string KUNCI_KOMPRESI = 'absen.kompresi_foto';

    public const string KUNCI_ABSEN_UMUM = 'absen.absen_umum_aktif';

    public const string KUNCI_JAM_MASUK_UMUM = 'absen.jam_masuk_umum';

    /** Jam masuk harian bawaan bila admin belum menyetelnya. */
    public const string JAM_MASUK_BAWAAN = '07:30';

    /** Batas ambang kecocokan wajah, mengikuti slider pada UIUX §3.5. */
    public const int AMBANG_MIN = 70;

    public const int AMBANG_MAKS = 99;

    /** Batas toleransi keterlambatan yang masuk akal untuk satu kegiatan. */
    public const int TOLERANSI_MAKS_MENIT = 180;

    public function __construct(
        protected PengaturanRepository $pengaturan,
        protected LogAktivitasService $log,
    ) {}

    /**
     * Seluruh pengaturan absen beserta nilai bawaannya.
     *
     * @return array<string, mixed>
     */
    public function ambil(): array
    {
        return [
            'metode_manual_aktif' => $this->bool(self::KUNCI_MANUAL, true),
            'metode_rfid_aktif' => $this->bool(self::KUNCI_RFID, true),
            'metode_wajah_aktif' => $this->bool(self::KUNCI_WAJAH, true),
            'toleransi_default_menit' => $this->int(self::KUNCI_TOLERANSI, 15),
            'ambang_kecocokan_wajah' => $this->int(self::KUNCI_AMBANG_WAJAH, 85),
            'kompresi_foto' => $this->kompresi()->value,

            /*
             * Absen umum: sesi harian yang dibuka sistem sendiri ketika tidak
             * ada kegiatan berjalan, memakai jam masuk dan toleransi di bawah
             * ini. Dimatikan bila instansi hanya ingin absensi berbasis event.
             */
            'absen_umum_aktif' => $this->bool(self::KUNCI_ABSEN_UMUM, true),
            'jam_masuk_umum' => $this->jam(self::KUNCI_JAM_MASUK_UMUM, self::JAM_MASUK_BAWAAN),
        ];
    }

    /**
     * Preset kompresi yang sedang berlaku, sudah dalam bentuk enum sehingga
     * pemanggil langsung memperoleh dimensi dan kualitasnya.
     */
    public function kompresi(): KompresiFoto
    {
        return KompresiFoto::tryFrom(
            (string) $this->pengaturan->ambil(self::KUNCI_KOMPRESI),
        ) ?? KompresiFoto::Sedang;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> pengaturan setelah disimpan
     */
    public function simpan(array $data, User $pelaku): array
    {
        $sebelum = $this->ambil();

        /*
         * Medan yang tidak dikirim mempertahankan nilainya. Formulir Setting
         * Absen selalu mengirim semuanya, tetapi pemanggil lain — penyiapan
         * data dan pengujian — kerap hanya menggeser satu medan, dan itu tidak
         * boleh diam-diam mengembalikan medan lain ke bawaan.
         */
        $nilai = fn (string $medan) => $data[$medan] ?? $sebelum[$medan];

        $this->pengaturan->simpanBanyak([
            self::KUNCI_MANUAL => $this->dariBool($nilai('metode_manual_aktif')),
            self::KUNCI_RFID => $this->dariBool($nilai('metode_rfid_aktif')),
            self::KUNCI_WAJAH => $this->dariBool($nilai('metode_wajah_aktif')),
            self::KUNCI_TOLERANSI => (string) (int) $nilai('toleransi_default_menit'),
            self::KUNCI_AMBANG_WAJAH => (string) (int) $nilai('ambang_kecocokan_wajah'),
            self::KUNCI_KOMPRESI => (string) $nilai('kompresi_foto'),
            self::KUNCI_ABSEN_UMUM => $this->dariBool($nilai('absen_umum_aktif')),
            self::KUNCI_JAM_MASUK_UMUM => substr((string) $nilai('jam_masuk_umum'), 0, 5),
        ]);

        $sesudah = $this->ambil();

        if ($sesudah !== $sebelum) {
            $this->log->catat(
                AksiLog::Ubah,
                'Mengubah Setting Absen: '.$this->ringkasPerubahan($sebelum, $sesudah).'.',
                user: $pelaku,
            );
        }

        return $sesudah;
    }

    /**
     * Ringkasan medan yang berubah, supaya audit trail menerangkan apa yang
     * bergeser alih-alih sekadar "pengaturan diubah".
     *
     * @param  array<string, mixed>  $sebelum
     * @param  array<string, mixed>  $sesudah
     */
    protected function ringkasPerubahan(array $sebelum, array $sesudah): string
    {
        $perubahan = [];

        foreach ($sesudah as $medan => $nilai) {
            if ($sebelum[$medan] === $nilai) {
                continue;
            }

            $perubahan[] = sprintf(
                '%s %s → %s',
                $medan,
                $this->untukLog($sebelum[$medan]),
                $this->untukLog($nilai),
            );
        }

        return implode(', ', $perubahan);
    }

    protected function untukLog(mixed $nilai): string
    {
        return match (true) {
            is_bool($nilai) => $nilai ? 'aktif' : 'nonaktif',
            default => (string) $nilai,
        };
    }

    protected function bool(string $kunci, bool $bawaan): bool
    {
        $nilai = $this->pengaturan->ambil($kunci);

        return $nilai === null ? $bawaan : $nilai === '1';
    }

    /**
     * Jam dalam bentuk HH:MM. Nilai yang tidak berbentuk jam dijatuhkan ke
     * bawaan alih-alih diteruskan — sesi absen umum memakainya sebagai
     * `jam_mulai`, dan baris rusak di tabel pengaturan tidak boleh membuat
     * pembuatan sesi gagal.
     */
    protected function jam(string $kunci, string $bawaan): string
    {
        $nilai = $this->pengaturan->ambil($kunci);

        return $nilai !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $nilai) === 1
            ? $nilai
            : $bawaan;
    }

    protected function int(string $kunci, int $bawaan): int
    {
        $nilai = $this->pengaturan->ambil($kunci);

        return $nilai === null || ! is_numeric($nilai) ? $bawaan : (int) $nilai;
    }

    protected function dariBool(mixed $nilai): string
    {
        return $nilai ? '1' : '0';
    }
}
