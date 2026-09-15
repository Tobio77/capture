<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Enums\KompresiFoto;
use App\Models\User;
use App\Support\PengaturanRepository;
use Illuminate\Support\Carbon;

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

    /*
     * Jendela operasional Absen Umum (FR-SET-07). Datang dan pulang dipisah,
     * bukan satu jendela besar: satu jendela 06.00–18.00 berarti "Pulang"
     * pukul 07.00 dan "Datang" pukul 17.00 sama-sama sah, dan sistem tidak
     * punya dasar menolaknya. Dua jendela membuat jenis absen yang keliru
     * tertolak dengan sendirinya.
     *
     * Berbeda dari `jam_masuk_umum`, yang menentukan batas TEPAT WAKTU: jendela
     * ini menentukan kapan tap diterima sama sekali. Keduanya berdampingan —
     * datang boleh dari 06.00, tetapi terhitung terlambat setelah 07.30.
     */
    public const string KUNCI_BUKA_DATANG = 'absen.jam_buka_datang';

    public const string KUNCI_TUTUP_DATANG = 'absen.jam_tutup_datang';

    public const string KUNCI_BUKA_PULANG = 'absen.jam_buka_pulang';

    public const string KUNCI_TUTUP_PULANG = 'absen.jam_tutup_pulang';

    /**
     * Jadwal jam per hari (Senin–Minggu), menggantikan satu jam yang sama
     * untuk seluruh hari. Kasus nyata yang mendorongnya: UPT yang jam
     * masuknya pukul 07.00 pada hari biasa tetapi 07.00-kurang pada hari
     * senam pagi, atau jendela pulang yang lebih pendek menjelang akhir
     * pekan.
     *
     * SENGAJA GLOBAL (bukan per unit kerja) — selaras dengan seluruh
     * pengaturan jam Absen Umum lain di kelas ini, yang juga global. Sakelar
     * "hari ini libur total" per unit SUDAH ADA lewat `UnitKerja.hari_kerja`
     * ({@see KalenderKerjaService}) — jadwal jam di sini hanya berarti pada
     * hari yang memang sudah dinyatakan hari kerja; hari libur/akhir pekan
     * tetap tertutup penuh apa pun isi jamnya.
     *
     * Disimpan sebagai SATU kunci berisi JSON (bukan 35 kunci terpisah):
     * `PengaturanRepository` murni key-value string, dan tujuh baris yang
     * selalu dibaca/ditulis bersamaan tidak ada gunanya dipecah.
     */
    public const string KUNCI_JADWAL_MINGGUAN = 'absen.jadwal_jam_mingguan';

    /** Hari ISO 1 (Senin) sampai 7 (Minggu) — urutan tetap dipakai jadwal mingguan. */
    public const array HARI_ISO = [1, 2, 3, 4, 5, 6, 7];

    public const string KUNCI_WAJIB_KODE_AKTIVASI = 'absen.wajib_kode_aktivasi';

    /**
     * Stempel waktu pelonggaran pengaman (perbaikan H-3).
     *
     * Bukan setelan yang dipilih admin melainkan catatan yang ditulis sistem
     * ketika sakelarnya digeser; lihat {@see self::catatPelonggaran()}.
     */
    public const string KUNCI_WAJAH_MATI_SEJAK = 'absen.verifikasi_wajah_mati_sejak';

    public const string KUNCI_TERBUKA_SEJAK = 'absen.mode_terbuka_sejak';

    /**
     * Ambang batas yang menyalakan rekomendasi pada Laporan Resmi
     * (FR-LAP-04). Bukan angka mati di kode: unit yang berlaku baginya kerap
     * berbeda konteks — UPT pelatihan dengan jadwal padat, bidang dengan
     * kegiatan lapangan — dan yang menilai wajar-tidaknya sebuah angka adalah
     * admin dinas, bukan pengembang aplikasi.
     */
    public const string KUNCI_AMBANG_KEHADIRAN_MINIMUM = 'laporan.ambang_kehadiran_minimum';

    public const string KUNCI_AMBANG_KETERLAMBATAN_MAKSIMUM = 'laporan.ambang_keterlambatan_maksimum';

    /** Jam masuk harian bawaan bila admin belum menyetelnya. */
    public const string JAM_MASUK_BAWAAN = '07:30';

    /** Batas ambang kecocokan wajah, mengikuti slider pada UIUX §3.5. */
    public const int AMBANG_MIN = 70;

    public const int AMBANG_MAKS = 99;

    /** Batas toleransi keterlambatan yang masuk akal untuk satu kegiatan. */
    public const int TOLERANSI_MAKS_MENIT = 180;

    /** Rentang wajar ambang kehadiran minimum pada Laporan Resmi, dalam persen. */
    public const int AMBANG_KEHADIRAN_MIN = 50;

    public const int AMBANG_KEHADIRAN_MAKS = 100;

    /** Bawaan sebelum admin pernah mengaturnya (FR-LAP-04). */
    public const int AMBANG_KEHADIRAN_BAWAAN = 80;

    /** Rentang wajar ambang keterlambatan maksimum, dalam persen. */
    public const int AMBANG_KETERLAMBATAN_MIN = 5;

    public const int AMBANG_KETERLAMBATAN_MAKS = 50;

    public const int AMBANG_KETERLAMBATAN_BAWAAN = 15;

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

            // Jendela operasional; lihat catatan pada konstantanya.
            'jam_buka_datang' => $this->jam(self::KUNCI_BUKA_DATANG, '06:00'),
            'jam_tutup_datang' => $this->jam(self::KUNCI_TUTUP_DATANG, '09:00'),
            'jam_buka_pulang' => $this->jam(self::KUNCI_BUKA_PULANG, '15:00'),
            'jam_tutup_pulang' => $this->jam(self::KUNCI_TUTUP_PULANG, '18:00'),

            // Jadwal jam per hari (Senin–Minggu); lihat catatan pada konstantanya.
            'jadwal_mingguan' => $this->jadwalMingguan(),

            /*
             * FR-SET-06. Bawaannya menyala: perangkat harus didaftarkan admin
             * dan menukarkan kode aktivasi lebih dahulu. Mematikannya membuka
             * layar absen bagi mesin mana pun yang dapat menjangkau alamatnya,
             * sehingga hanya untuk keadaan darurat — dan admin diberi peringatan
             * yang selalu terlihat selama mode itu menyala.
             */
            'wajib_kode_aktivasi' => $this->bool(self::KUNCI_WAJIB_KODE_AKTIVASI, true),

            /*
             * FR-LAP-04. Dipakai bagian Rekomendasi pada Laporan Resmi untuk
             * menandai unit yang perlu tindak lanjut — lihat LaporanResmiService.
             */
            'ambang_kehadiran_minimum' => $this->int(
                self::KUNCI_AMBANG_KEHADIRAN_MINIMUM,
                self::AMBANG_KEHADIRAN_BAWAAN,
            ),
            'ambang_keterlambatan_maksimum' => $this->int(
                self::KUNCI_AMBANG_KETERLAMBATAN_MAKSIMUM,
                self::AMBANG_KETERLAMBATAN_BAWAAN,
            ),
        ];
    }

    /**
     * Mode Terbuka: perangkat boleh masuk tanpa kode aktivasi.
     *
     * Dipisahkan sebagai method sendiri karena dibaca dari banyak tempat —
     * layar aktivasi, pembuatan perangkat ad-hoc, dan spanduk peringatan di
     * panel admin — dan ketiganya harus selalu sepakat.
     */
    public function modeTerbuka(): bool
    {
        return ! $this->ambil()['wajib_kode_aktivasi'];
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
     * Jadwal jam ketujuh hari, Senin (1) sampai Minggu (7).
     *
     * Bila belum pernah disimpan, disintesis dari jam global lama
     * (`jam_masuk_umum`, `jam_buka_datang`, dst.) direplikasi ke ketujuh
     * hari — sehingga jam yang sudah dikonfigurasi admin sebelum fitur ini
     * ada TIDAK hilang, hanya menjadi titik awal yang sama untuk semua hari.
     *
     * @return array<int, array{hari: int, jam_masuk: string, jam_buka_datang: string, jam_tutup_datang: string, jam_buka_pulang: string, jam_tutup_pulang: string}>
     */
    public function jadwalMingguan(): array
    {
        $mentah = $this->pengaturan->ambil(self::KUNCI_JADWAL_MINGGUAN);
        $tersimpan = $mentah === null ? null : json_decode($mentah, true);

        if (! is_array($tersimpan)) {
            return $this->jadwalBawaan();
        }

        $perHari = collect($tersimpan)
            ->filter(fn ($baris) => is_array($baris) && isset($baris['hari']))
            ->keyBy('hari');

        // Baris yang tidak lengkap (mis. hari baru ditambahkan seiring versi
        // aplikasi berikutnya) jatuh ke bawaan yang sama seperti sebelum
        // fitur ini ada, bukan menjadikan seluruh jadwal tidak terbaca.
        return collect(self::HARI_ISO)
            ->map(function (int $hari) use ($perHari) {
                $baris = $perHari->get($hari);

                return is_array($baris) ? $this->bariskanJadwal($hari, $baris) : $this->barisJadwalBawaan($hari);
            })
            ->all();
    }

    /**
     * Jadwal jam satu hari saja — dipakai AbsenUmumService untuk menentukan
     * jendela/jam masuk yang berlaku pada hari tertentu.
     *
     * @return array{hari: int, jam_masuk: string, jam_buka_datang: string, jam_tutup_datang: string, jam_buka_pulang: string, jam_tutup_pulang: string}
     */
    public function jadwalUntukHari(int $isoHari): array
    {
        return collect($this->jadwalMingguan())->firstWhere('hari', $isoHari)
            ?? $this->barisJadwalBawaan($isoHari);
    }

    /**
     * @return array<int, array{hari: int, jam_masuk: string, jam_buka_datang: string, jam_tutup_datang: string, jam_buka_pulang: string, jam_tutup_pulang: string}>
     */
    protected function jadwalBawaan(): array
    {
        return collect(self::HARI_ISO)->map(fn (int $hari) => $this->barisJadwalBawaan($hari))->all();
    }

    /**
     * @return array{hari: int, jam_masuk: string, jam_buka_datang: string, jam_tutup_datang: string, jam_buka_pulang: string, jam_tutup_pulang: string}
     */
    protected function barisJadwalBawaan(int $hari): array
    {
        return [
            'hari' => $hari,
            'jam_masuk' => $this->jam(self::KUNCI_JAM_MASUK_UMUM, self::JAM_MASUK_BAWAAN),
            'jam_buka_datang' => $this->jam(self::KUNCI_BUKA_DATANG, '06:00'),
            'jam_tutup_datang' => $this->jam(self::KUNCI_TUTUP_DATANG, '09:00'),
            'jam_buka_pulang' => $this->jam(self::KUNCI_BUKA_PULANG, '15:00'),
            'jam_tutup_pulang' => $this->jam(self::KUNCI_TUTUP_PULANG, '18:00'),
        ];
    }

    /**
     * @param  array<string, mixed>  $baris
     * @return array{hari: int, jam_masuk: string, jam_buka_datang: string, jam_tutup_datang: string, jam_buka_pulang: string, jam_tutup_pulang: string}
     */
    protected function bariskanJadwal(int $hari, array $baris): array
    {
        $bawaan = $this->barisJadwalBawaan($hari);
        $jam = fn (string $medan) => $this->jamAtauBawaan($baris[$medan] ?? null, $bawaan[$medan]);

        return [
            'hari' => $hari,
            'jam_masuk' => $jam('jam_masuk'),
            'jam_buka_datang' => $jam('jam_buka_datang'),
            'jam_tutup_datang' => $jam('jam_tutup_datang'),
            'jam_buka_pulang' => $jam('jam_buka_pulang'),
            'jam_tutup_pulang' => $jam('jam_tutup_pulang'),
        ];
    }

    protected function jamAtauBawaan(mixed $nilai, string $bawaan): string
    {
        return is_string($nilai) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $nilai) === 1
            ? $nilai
            : $bawaan;
    }

    /**
     * Rapikan jadwal mingguan sebelum disimpan sebagai JSON — dijamin selalu
     * tujuh baris berurutan Senin..Minggu apa pun urutan/kelengkapan yang
     * dikirim pemanggil, sehingga `jadwalMingguan()` tidak perlu menduga-duga
     * saat membacanya kembali.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rapikanJadwal(mixed $nilai): array
    {
        $perHari = collect(is_array($nilai) ? $nilai : [])
            ->filter(fn ($baris) => is_array($baris) && isset($baris['hari']))
            ->keyBy('hari');

        return collect(self::HARI_ISO)
            ->map(fn (int $hari) => $this->bariskanJadwal($hari, $perHari->get($hari, [])))
            ->all();
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

        $simpanan = [
            self::KUNCI_MANUAL => $this->dariBool($nilai('metode_manual_aktif')),
            self::KUNCI_RFID => $this->dariBool($nilai('metode_rfid_aktif')),
            self::KUNCI_WAJAH => $this->dariBool($nilai('metode_wajah_aktif')),
            self::KUNCI_TOLERANSI => (string) (int) $nilai('toleransi_default_menit'),
            self::KUNCI_AMBANG_WAJAH => (string) (int) $nilai('ambang_kecocokan_wajah'),
            self::KUNCI_KOMPRESI => (string) $nilai('kompresi_foto'),
            self::KUNCI_ABSEN_UMUM => $this->dariBool($nilai('absen_umum_aktif')),
            self::KUNCI_JAM_MASUK_UMUM => substr((string) $nilai('jam_masuk_umum'), 0, 5),
            self::KUNCI_BUKA_DATANG => substr((string) $nilai('jam_buka_datang'), 0, 5),
            self::KUNCI_TUTUP_DATANG => substr((string) $nilai('jam_tutup_datang'), 0, 5),
            self::KUNCI_BUKA_PULANG => substr((string) $nilai('jam_buka_pulang'), 0, 5),
            self::KUNCI_TUTUP_PULANG => substr((string) $nilai('jam_tutup_pulang'), 0, 5),
            self::KUNCI_WAJIB_KODE_AKTIVASI => $this->dariBool($nilai('wajib_kode_aktivasi')),
            self::KUNCI_AMBANG_KEHADIRAN_MINIMUM => (string) (int) $nilai('ambang_kehadiran_minimum'),
            self::KUNCI_AMBANG_KETERLAMBATAN_MAKSIMUM => (string) (int) $nilai('ambang_keterlambatan_maksimum'),
        ];

        /*
         * Jadwal mingguan HANYA ditulis ulang bila pemanggil benar-benar
         * mengirimkannya (form Setting Absen selalu mengirim — lihat
         * Setting/Absen.vue). Bila tidak, `jadwal_mingguan` tidak disentuh
         * sama sekali — bukan ditulis ulang dari `$sebelum['jadwal_mingguan']`.
         *
         * Alasannya: `$sebelum` diambil SEBELUM baris jam_* di atas
         * tersimpan. Pemanggil yang hanya mengubah `jam_buka_pulang` lewat
         * jalur lama (mis. test, atau kode lain yang belum pindah ke jadwal
         * per hari) akan membekukan jadwal_mingguan dari nilai LAMA yang
         * belum berubah — jendela barunya tidak pernah benar-benar berlaku
         * sebab status()/buatSesi() kini membaca jadwal_mingguan, bukan kunci
         * jam_* lagi. Selama jadwal_mingguan belum pernah tersimpan sama
         * sekali, ia tetap disintesis LIVE dari kunci jam_* setiap dibaca
         * (lihat jadwalMingguan()) — sehingga jalur lama itu tetap berlaku
         * seperti sebelum fitur ini ada, sampai admin benar-benar membuka
         * dan menyimpan tabel jadwal per hari yang baru.
         */
        if (array_key_exists('jadwal_mingguan', $data)) {
            $simpanan[self::KUNCI_JADWAL_MINGGUAN] = json_encode($this->rapikanJadwal($data['jadwal_mingguan']));
        }

        $this->pengaturan->simpanBanyak($simpanan);

        $sesudah = $this->ambil();

        $this->catatPelonggaran($sebelum, $sesudah);

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
     * Catat SEJAK KAPAN sebuah pengaman dilonggarkan (perbaikan H-3).
     *
     * Dua sakelar pada layar ini melonggarkan pengaman, dan keduanya mudah
     * dinyalakan untuk satu apel pagi yang terburu-buru lalu terlupakan
     * berminggu-minggu. Tanpa stempel waktu, tidak ada yang dapat membedakan
     * "baru dimatikan lima menit lalu" dari "sudah mati sejak bulan lalu" —
     * dan panel Perhatian tidak punya dasar untuk mengingatkan siapa pun.
     *
     * Waktunya dihapus begitu pengamannya menyala kembali, sehingga pemulihan
     * yang benar tidak meninggalkan peringatan yang menggantung.
     *
     * @param  array<string, mixed>  $sebelum
     * @param  array<string, mixed>  $sesudah
     */
    protected function catatPelonggaran(array $sebelum, array $sesudah): void
    {
        $pengaman = [
            'metode_wajah_aktif' => self::KUNCI_WAJAH_MATI_SEJAK,
            'wajib_kode_aktivasi' => self::KUNCI_TERBUKA_SEJAK,
        ];

        foreach ($pengaman as $medan => $kunci) {
            if ($sebelum[$medan] === $sesudah[$medan]) {
                continue;
            }

            $this->pengaturan->simpan(
                $kunci,
                $sesudah[$medan] ? null : Carbon::now()->toIso8601String(),
            );
        }
    }

    /**
     * Sejak kapan sebuah pengaman dilonggarkan, atau null bila ia menyala.
     *
     * Instalasi yang sakelarnya sudah dimatikan SEBELUM stempel waktu ini
     * diperkenalkan tidak punya nilainya; dalam keadaan itu panel Perhatian
     * tetap memperingatkan, hanya tanpa menyebut lamanya.
     */
    public function dilonggarkanSejak(string $kunci): ?Carbon
    {
        $nilai = $this->pengaturan->ambil($kunci);

        return blank($nilai) ? null : Carbon::parse($nilai);
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

            // Jadwal mingguan berbentuk array tujuh baris, bukan skalar —
            // diffnya sendiri tidak ada gunanya dibaca sebagai satu untai
            // "sebelum → sesudah", jadi cukup ditandai berubah.
            if ($medan === 'jadwal_mingguan') {
                $perubahan[] = 'jadwal jam mingguan diubah';

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
