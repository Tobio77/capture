<?php

namespace App\Services;

use App\Enums\JenisAbsen;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sinyal yang menuntut tindakan admin, untuk panel "Perlu Perhatian".
 *
 * **Mengapa ini yang dipilih.** Dashboard sebelumnya menjawab "berapa" —
 * berapa hadir, berapa terlambat, berapa perangkat. Semuanya benar dan
 * semuanya pasif: tidak satu pun memberi tahu admin bahwa ada sesuatu yang
 * perlu ia KERJAKAN. Tiga sinyal di bawah dipilih karena masing-masing punya
 * tindakan yang jelas, dan ketiganya tidak terlihat dari angka mana pun yang
 * sudah ada di halaman itu.
 *
 * **Aturan yang dipegang, warisan cacat kartu statistik lama.** Setiap butir
 * hanya muncul ketika ia benar-benar menyala, dan selalu menyebut ANGKA yang
 * membuatnya menyala. Tidak ada bar kemajuan, tidak ada penyebut yang dikarang
 * supaya ada yang bisa digambar: indikator yang dipasang pada angka tanpa
 * penyebut nyata adalah yang dulu membuat "Total Pegawai 666" memperoleh jalur
 * kosong yang tidak pernah berarti apa-apa.
 *
 * Panel ini menghilang sepenuhnya ketika tidak ada yang perlu diperhatikan.
 * Panel kosong berisi kalimat "semua aman" adalah ruang yang dibayar setiap
 * hari untuk kabar yang hanya berguna sesekali.
 */
class PerhatianDashboardService
{
    /**
     * Batas sunyi sebuah perangkat sebelum dianggap patut ditengok.
     *
     * Tiga hari, bukan satu: akhir pekan dan hari libur membuat perangkat
     * mana pun sunyi dua hari berturut-turut secara wajar, dan peringatan
     * yang menyala setiap Senin pagi akan berhenti dibaca pada Senin ketiga.
     */
    public const int HARI_SUNYI = 3;

    /** Jumlah pegawai minimum sebuah unit sebelum kehadirannya dibandingkan. */
    public const int MINIMAL_PEGAWAI = 5;

    /** Ambang "jauh di bawah kebiasaan": separuh rata-rata unit itu sendiri. */
    public const float AMBANG_ANJLOK = 0.5;

    /**
     * Rata-rata kehadiran minimum sebelum sebuah unit layak dibandingkan.
     *
     * Unit yang biasanya dihadiri satu orang akan "anjlok 100%" setiap kali
     * orang itu izin. Membandingkan terhadap rata-rata sekecil itu
     * menghasilkan kalimat yang benar secara hitungan tetapi tidak berguna
     * bagi siapa pun: "baru 0 hadir hari ini; biasanya sekitar 1".
     */
    public const float MINIMAL_RATA = 3.0;

    /**
     * Batas jumlah butir yang ditampilkan sekaligus.
     *
     * Panel berisi tujuh peringatan bukan lagi daftar tindakan melainkan
     * dinding teks, dan yang paling penting justru tenggelam di dalamnya.
     */
    public const int BATAS_BUTIR = 6;

    public function __construct(
        protected SettingAbsenService $setting,
        protected KalenderKerjaService $kalender,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function untuk(User $pelaku): array
    {
        /*
         * Urutannya tetap dan disengaja: kegiatan yang lupa ditutup merusak
         * data selama ia dibiarkan, sementara perangkat sunyi hanya perlu
         * ditengok. Ketika lariknya dipotong, yang tersisa adalah yang paling
         * mahal bila diabaikan.
         */
        return array_slice(array_values(array_filter([
            ...$this->kegiatanLupaDitutup($pelaku),
            ...$this->kehadiranAnjlok($pelaku),
            ...$this->perangkatSunyi($pelaku),
        ])), 0, self::BATAS_BUTIR);
    }

    /**
     * Kegiatan yang masih dibuka padahal tanggalnya sudah lewat.
     *
     * Risiko nyata, bukan kerapian: selama entry-nya terbuka, tap hari ini
     * tercatat pada kegiatan KEMARIN — dan rekap kedua-duanya menjadi salah
     * tanpa ada yang menyadarinya sampai laporan bulanan disusun.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function kegiatanLupaDitutup(User $pelaku): array
    {
        $event = EventAbsen::query()
            ->kegiatan()
            ->aktif()
            ->whereDate('tanggal', '<', Carbon::today())
            ->when(! $pelaku->lintasUnit(), fn ($q) => $q->menyentuhUnit(
                UnitKerja::idsDenganTurunan($pelaku->unit_kerja_id),
            ))
            ->orderBy('tanggal')
            ->limit(5)
            ->get();

        return $event->map(fn (EventAbsen $satu) => [
            'jenis' => 'event_terbuka',
            'nada' => 'amber',
            'ikon' => 'jam',
            'judul' => $satu->nama,
            'keterangan' => sprintf(
                'Entry masih dibuka sejak %s. Tap hari ini akan tercatat pada kegiatan tanggal itu.',
                $satu->tanggal->translatedFormat('d F'),
            ),
            'aksi' => 'Tutup event',
            'url' => '/admin/kelola-absen/event?event_absen_id='.$satu->id,
        ])->all();
    }

    /**
     * Perangkat yang tercatat aktif tetapi sudah lama tidak melayani tap.
     *
     * Dua keadaan yang terlihat sama pada Daftar Perangkat — "aktif" —
     * padahal yang satu sedang bekerja dan yang satu sudah dicabut orang dari
     * stopkontak berminggu-minggu lalu.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function perangkatSunyi(User $pelaku): array
    {
        $batas = Carbon::today()->subDays(self::HARI_SUNYI);

        $tapTerakhir = Absensi::query()
            ->selectRaw('kiosk_id, MAX(waktu) as terakhir')
            ->whereNotNull('kiosk_id')
            ->groupBy('kiosk_id');

        $kiosk = Kiosk::query()
            ->where('aktif', true)

            /*
             * Perangkat yang BARU didaftarkan memang belum melayani tap, dan
             * itu bukan kelalaian siapa pun. Tanpa syarat ini, setiap
             * pendaftaran perangkat baru langsung menyalakan peringatan pada
             * hari yang sama — dan lima perangkat contoh membuat panel ini
             * berisi lima butir yang tidak menuntut tindakan apa pun.
             */
            ->where('diaktifkan_pada', '<', $batas)
            ->leftJoinSub($tapTerakhir, 'tap', 'tap.kiosk_id', '=', 'kiosk.id')
            ->when(! $pelaku->lintasUnit(), fn ($q) => $q->whereIn(
                'unit_kerja_id',
                UnitKerja::idsDenganTurunan($pelaku->unit_kerja_id),
            ))
            ->where(fn ($q) => $q->whereNull('tap.terakhir')->orWhere('tap.terakhir', '<', $batas))
            ->orderBy('tap.terakhir')
            ->limit(5)
            ->get(['kiosk.*', 'tap.terakhir']);

        return $kiosk->map(fn (Kiosk $satu) => [
            'jenis' => 'perangkat_sunyi',
            'nada' => 'biru',
            'ikon' => 'perangkat',
            'judul' => $satu->nama_titik,
            'keterangan' => $satu->terakhir === null
                ? 'Tercatat aktif, tetapi belum pernah melayani satu tap pun.'
                : sprintf(
                    'Tercatat aktif, tetapi tap terakhirnya %s.',
                    Carbon::parse($satu->terakhir)->diffForHumans(),
                ),
            'aksi' => 'Lihat perangkat',
            'url' => '/admin/perangkat',
        ])->all();
    }

    /**
     * Unit kerja yang kehadiran hari ini jauh di bawah kebiasaannya sendiri.
     *
     * Dibandingkan terhadap DIRINYA SENDIRI, bukan terhadap unit lain: sebuah
     * seksi berisi delapan orang dan sebuah UPT berisi dua ratus orang tidak
     * pernah sebanding, dan peringkat antar-unit yang sudah ada di Dashboard
     * justru selalu menempatkan yang kecil di bawah tanpa ada yang salah.
     *
     * Tidak dihitung sebelum batas tepat waktu terlewat. Pada pukul tujuh
     * pagi setiap unit "anjlok", dan peringatan yang selalu menyala pada jam
     * yang sama setiap hari berhenti menjadi peringatan.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function kehadiranAnjlok(User $pelaku): array
    {
        if (! $this->lewatBatasMasuk()) {
            return [];
        }

        /*
         * Hari libur tidak dinilai sama sekali.
         *
         * Tanpa penjagaan ini, setiap Senin pagi panel Perlu Perhatian akan
         * penuh laporan bahwa kehadiran Sabtu dan Minggu "anjlok" — benar
         * secara hitungan, dan tidak menuntut tindakan apa pun dari siapa pun.
         * Peringatan yang menyala setiap awal pekan berhenti dibaca pada pekan
         * kedua, dan sesudah itu ia tidak lagi menyelamatkan siapa pun ketika
         * benar-benar menyala.
         */
        if (! $this->kalender->hariKerja(
            $pelaku->lintasUnit() ? null : $pelaku->unit_kerja_id,
        )) {
            return [];
        }

        $cakupan = $pelaku->lintasUnit()
            ? null
            : UnitKerja::idsDenganTurunan($pelaku->unit_kerja_id);

        /*
         * Satu kueri untuk dua angka sekaligus: kehadiran hari ini dan
         * rata-rata harian unit yang sama pada tujuh hari sebelumnya. Menarik
         * keduanya terpisah berarti dua perjalanan ke basis data untuk
         * pertanyaan yang sebenarnya satu.
         */
        $baris = DB::table('absensi')
            ->join('pegawai', 'pegawai.id', '=', 'absensi.pegawai_id')
            ->join('unit_kerja', 'unit_kerja.id', '=', 'pegawai.unit_kerja_id')
            ->where('absensi.jenis', JenisAbsen::Datang->value)
            ->whereBetween('absensi.waktu', [
                Carbon::today()->subDays(7)->startOfDay(),
                Carbon::now(),
            ])
            ->when($cakupan !== null, fn ($q) => $q->whereIn('pegawai.unit_kerja_id', $cakupan))
            ->groupBy('unit_kerja.id', 'unit_kerja.nama')
            ->selectRaw('unit_kerja.id, unit_kerja.nama')
            ->selectRaw('SUM(CASE WHEN DATE(absensi.waktu) = ? THEN 1 ELSE 0 END) as hari_ini', [
                Carbon::today()->toDateString(),
            ])
            ->selectRaw('COUNT(DISTINCT CASE WHEN DATE(absensi.waktu) < ? THEN DATE(absensi.waktu) END) as hari_lalu', [
                Carbon::today()->toDateString(),
            ])
            ->selectRaw('SUM(CASE WHEN DATE(absensi.waktu) < ? THEN 1 ELSE 0 END) as jumlah_lalu', [
                Carbon::today()->toDateString(),
            ])
            ->get();

        $jumlahPegawai = DB::table('pegawai')
            ->where('aktif', true)
            ->when($cakupan !== null, fn ($q) => $q->whereIn('unit_kerja_id', $cakupan))
            ->groupBy('unit_kerja_id')
            ->pluck(DB::raw('COUNT(*)'), 'unit_kerja_id');

        return $baris
            ->filter(function ($u) use ($jumlahPegawai) {
                // Unit terlalu kecil: selisih satu orang sudah 20%, dan
                // peringatannya akan menyala setiap kali seseorang cuti.
                if ((int) ($jumlahPegawai[$u->id] ?? 0) < self::MINIMAL_PEGAWAI) {
                    return false;
                }

                // Belum punya kebiasaan yang dapat dibandingkan.
                if ((int) $u->hari_lalu === 0) {
                    return false;
                }

                $rata = $u->jumlah_lalu / $u->hari_lalu;

                // Kebiasaan yang terlalu tipis bukan kebiasaan.
                if ($rata < self::MINIMAL_RATA) {
                    return false;
                }

                return $u->hari_ini < $rata * self::AMBANG_ANJLOK;
            })
            ->sortBy(fn ($u) => $u->hari_ini / max(1, $u->jumlah_lalu / max(1, $u->hari_lalu)))
            ->take(3)
            ->map(fn ($u) => [
                'jenis' => 'kehadiran_anjlok',
                'nada' => 'amber',
                'ikon' => 'pegawai',
                'judul' => $u->nama,
                'keterangan' => sprintf(
                    'Baru %d hadir hari ini; biasanya sekitar %d pada jam ini.',
                    $u->hari_ini,
                    (int) round($u->jumlah_lalu / $u->hari_lalu),
                ),
                'aksi' => 'Buka rekap',
                'url' => '/admin/kelola-absen/rekap?tab=umum&unit_kerja_id='.$u->id,
            ])
            ->values()
            ->all();
    }

    /**
     * Sudahkah batas tepat waktu harian terlewat hari ini.
     */
    protected function lewatBatasMasuk(): bool
    {
        $setting = $this->setting->ambil();
        [$jam, $menit] = explode(':', $setting['jam_masuk_umum']);

        return Carbon::now()->gt(
            Carbon::today()
                ->setTime((int) $jam, (int) $menit)
                ->addMinutes((int) $setting['toleransi_default_menit']),
        );
    }
}
