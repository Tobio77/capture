<?php

namespace App\Services\Laporan;

use App\Models\EventAbsen;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\KalenderKerjaService;
use App\Services\LaporanService;
use App\Services\SettingAbsenService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Susunan data Laporan Resmi — dokumen kop surat, bukan tabel mentah
 * (FR-LAP-04).
 *
 * Sengaja terpisah dari {@see LaporanService}, yang tetap menjadi satu-
 * satunya sumber baris per pegawai (dipakai layar, "Unduh Data", dan di sini).
 * "Data" dan "Laporan" adalah dua jalur kode yang berbeda tujuannya: yang
 * pertama tabel mentah untuk diolah lebih lanjut, yang kedua narasi siap cetak
 * dengan kesimpulan dan rekomendasi — keduanya tidak boleh saling menyalin
 * logika penghitungan, hanya berbagi SUMBER datanya.
 *
 * **Dua penyebut yang berbeda, disatukan sengaja.** Kehadiran kegiatan
 * (event) dan kehadiran harian (Absen Umum) dihitung dengan penyebut yang
 * berbeda karakternya:
 *
 *   - Kegiatan: penyebutnya jumlah event KEGIATAN yang BENAR-BENAR DIBUAT
 *     admin. Keberadaannya sudah pasti, sebab event kegiatan tidak pernah
 *     lahir sendiri — dihitung ULANG di sini, terpisah dari `event_berlaku`
 *     milik LaporanService (lihat catatan penting di bawah), khusus
 *     berjenis kegiatan.
 *   - Harian: penyebutnya BUKAN jumlah sesi Absen Umum yang kebetulan ada di
 *     tabel `event_absen` — sesi itu lahir lambat, hanya saat tap pertama
 *     terjadi, sehingga hari kerja yang kebetulan sepi tap sama sekali tidak
 *     akan pernah punya sesi sama sekali, dan penyebut yang menghitung sesi
 *     akan diam-diam mengecualikan hari itu — membuat kehadiran tampak
 *     sempurna padahal harinya tidak pernah benar-benar dihitung. Penyebut
 *     yang benar adalah {@see KalenderKerjaService::hariKerja()} diiterasi
 *     atas tanggal dalam periode, sebab itulah satu-satunya sumber kebenaran
 *     "hari ini seharusnya ada yang hadir", terlepas dari apakah ada tap.
 *
 * **Mengapa `event_berlaku` milik LaporanService TIDAK dipakai langsung
 * sebagai penyebut kegiatan.** Kolom itu sengaja menghitung SELURUH baris
 * `event_absen` yang berlaku bagi unit — kegiatan MAUPUN sesi Absen Umum
 * yang kebetulan sudah lahir — karena bagi "Unduh Data" keduanya memang satu
 * kewajiban yang sama (SDD §1: absen harian dibuat sebagai satu sesi event
 * biasa, supaya seluruh mesin yang sudah ada bekerja tanpa perubahan). Kalau
 * angka itu dipakai lagi DI SINI sebagai bagian "kegiatan", lalu hari kerja
 * kalender ditambahkan di atasnya sebagai bagian "harian", hari yang
 * kebetulan sudah py sesi Absen Umum akan terhitung DUA KALI — sekali lewat
 * `event_berlaku`, sekali lagi lewat kalender. Karena itu bagian kegiatan
 * dihitung ulang di {@see self::kegiatanBerlakuPerUnit()}, disaring khusus
 * `EventAbsen::scopeKegiatan()`, sebelum digabung dengan bagian harian.
 *
 * Baris mentah `hadir`/`terlambat`/`tanpa_keterangan` yang TAMPIL pada tabel
 * Ringkasan Data tetap persis angka LaporanService — supaya admin yang
 * membandingkan Laporan Resmi dengan "Unduh Data" untuk periode yang sama
 * melihat angka yang cocok. Yang berbeda hanya PENYEBUT di balik layar untuk
 * menghitung persentase kesimpulan dan ambang rekomendasi.
 */
class LaporanResmiService
{
    public function __construct(
        protected LaporanService $laporan,
        protected SettingAbsenService $setting,
        protected KalenderKerjaService $kalender,
    ) {}

    /**
     * Susun seluruh data yang dibutuhkan ketiga format cetak (PDF/Word/Excel).
     *
     * @return array<string, mixed>
     */
    public function susun(User $pelaku, Carbon $dari, Carbon $sampai, ?int $unitKerjaId): array
    {
        $sekarang = $this->ringkasanPeriode($pelaku, $dari, $sampai, $unitKerjaId);

        [$dariPembanding, $sampaiPembanding] = $this->periodePembanding($dari, $sampai);
        $sebelumnya = $this->ringkasanPeriode($pelaku, $dariPembanding, $sampaiPembanding, $unitKerjaId);

        return [
            'dari' => $dari,
            'sampai' => $sampai,
            'periode_label' => $this->labelPeriode($dari, $sampai),
            'cakupan' => $this->namaCakupan($pelaku, $unitKerjaId),
            'per_unit' => $sekarang['per_unit'],
            'total' => $sekarang['total'],
            'periode_pembanding_label' => $this->labelPeriode($dariPembanding, $sampaiPembanding),
            'total_pembanding' => $sebelumnya['total'],
            'kesimpulan' => $this->kesimpulan($dari, $sampai, $sekarang['total'], $dariPembanding, $sampaiPembanding, $sebelumnya['total']),
            'rekomendasi' => $this->rekomendasi($sekarang['per_unit']),
        ];
    }

    /**
     * Ringkasan satu periode: rincian per unit beserta totalnya.
     *
     * @return array{per_unit: array<int, array<string, mixed>>, total: array<string, mixed>}
     */
    protected function ringkasanPeriode(User $pelaku, Carbon $dari, Carbon $sampai, ?int $unitKerjaId): array
    {
        $baris = $this->laporan->rekap($pelaku, $dari, $sampai, $unitKerjaId)['baris'];

        if ($baris->isEmpty()) {
            return ['per_unit' => [], 'total' => $this->totalDari([])];
        }

        // RAW unit_kerja_id pegawai (seksi/subbag) — dibutuhkan dua kali:
        // menaikkan ke id level teratas untuk pengelompokan, dan mencari
        // kegiatan yang berlaku baginya (event bisa dicakupkan langsung ke
        // seksi, bukan cuma ke UPT/bidang di atasnya).
        $unitMentahPerPegawai = Pegawai::query()
            ->whereIn('id', $baris->pluck('pegawai_id'))
            ->pluck('unit_kerja_id', 'id');

        $unitTeratasPerPegawai = $unitMentahPerPegawai
            ->map(fn (?int $unitId) => ($unitId === null ? null : UnitKerja::idTeratasUntuk($unitId)) ?? $unitId ?? 0);

        $kegiatanBerlakuPerUnit = $this->kegiatanBerlakuPerUnit($dari, $sampai);

        // Dibaca SEKALI di sini, bukan per unit: setting tidak berubah di
        // tengah penyusunan satu laporan.
        $absenUmumAktif = (bool) $this->setting->ambil()['absen_umum_aktif'];

        $perUnit = $baris
            ->groupBy(fn (array $isi) => $unitTeratasPerPegawai[$isi['pegawai_id']] ?? 0)
            ->map(function (Collection $anggota, $unitTeratasId) use (
                $dari, $sampai, $absenUmumAktif, $unitMentahPerPegawai, $kegiatanBerlakuPerUnit,
            ) {
                // Kegiatan dijumlah per pegawai (bukan sekali per unit): dua
                // pegawai dalam satu unit teratas yang sama bisa punya jumlah
                // kegiatan berlaku berbeda, sebab event dapat dicakupkan
                // langsung ke seksi tertentu saja.
                $kegiatanBerlaku = $anggota->sum(
                    fn (array $isi) => $kegiatanBerlakuPerUnit[$unitMentahPerPegawai[$isi['pegawai_id']] ?? 0] ?? 0,
                );

                return $this->ringkasanUnit((int) $unitTeratasId, $anggota, $kegiatanBerlaku, $dari, $sampai, $absenUmumAktif);
            })
            ->sortBy('nama')
            ->values()
            ->all();

        return ['per_unit' => $perUnit, 'total' => $this->totalDari($perUnit)];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $anggota  baris pegawai milik satu unit teratas
     * @return array<string, mixed>
     */
    protected function ringkasanUnit(
        int $unitTeratasId,
        Collection $anggota,
        int $kegiatanBerlaku,
        Carbon $dari,
        Carbon $sampai,
        bool $absenUmumAktif,
    ): array {
        $hadir = (int) $anggota->sum('hadir');
        $terlambat = (int) $anggota->sum('terlambat');
        $jumlahPegawai = $anggota->count();

        /*
         * Hari kerja dikalikan jumlah pegawai: setiap pegawai menanggung
         * ekspektasi hari kerjanya SENDIRI-SENDIRI, persis seperti kegiatan
         * yang juga dihitung per pegawai.
         */
        $hariKerjaHarian = $absenUmumAktif
            ? $this->hariKerjaDalamRentang($unitTeratasId, $dari, $sampai) * $jumlahPegawai
            : 0;

        $totalEkspektasi = $kegiatanBerlaku + $hariKerjaHarian;

        return [
            'unit_kerja_id' => $unitTeratasId,
            'nama' => $anggota->first()['unit_kerja'] ?? 'Tanpa Unit',
            'pegawai' => $jumlahPegawai,

            // Angka tampilan tabel Ringkasan Data — persis LaporanService,
            // supaya cocok dengan "Unduh Data" pada periode yang sama.
            'hadir' => $hadir,
            'tepat' => max(0, $hadir - $terlambat),
            'terlambat' => $terlambat,
            'tanpa_keterangan' => (int) $anggota->sum('tanpa_keterangan'),

            'total_ekspektasi' => $totalEkspektasi,

            /*
             * null, bukan 0, ketika ekspektasinya sendiri nol — unit yang
             * tidak punya satu pun kegiatan atau hari kerja berlaku baginya
             * tidak "gagal hadir", ia sekadar tidak punya kewajiban apa pun
             * pada periode ini. Dipakai rekomendasi() untuk MENGECUALIKAN
             * unit ini dari evaluasi ambang, bukan menandainya 0%.
             */
            'tingkat_kehadiran' => $totalEkspektasi > 0 ? round($hadir / $totalEkspektasi * 100, 1) : null,
            'tingkat_keterlambatan' => $hadir > 0 ? round($terlambat / $hadir * 100, 1) : null,
        ];
    }

    /**
     * Berapa event KEGIATAN yang berlaku untuk setiap unit kerja mentah.
     *
     * Sengaja SALINAN kecil dari `LaporanService::eventPerUnit()`, bukan
     * dipakai bersama — method itu `protected` dan menghitung kegiatan
     * MAUPUN sesi Absen Umum sekaligus, sedangkan di sini keduanya justru
     * harus dihitung dengan penyebut yang berbeda (lihat catatan kelas).
     * Menyatukannya berarti mengikat dua kebutuhan yang akan berkembang ke
     * arah berbeda hanya untuk menghindari satu query pendek.
     *
     * @return array<int, int>
     */
    protected function kegiatanBerlakuPerUnit(Carbon $dari, Carbon $sampai): array
    {
        // whereDate(), bukan whereBetween('tanggal', [...]) mentah — lihat
        // catatan pada LaporanService::eventPadaRentang(), sumber pola ini.
        $event = EventAbsen::query()
            ->with('unitKerja:id')
            ->kegiatan()
            ->whereDate('tanggal', '>=', $dari->toDateString())
            ->whereDate('tanggal', '<=', $sampai->toDateString())
            ->get();

        $semuaUnitId = UnitKerja::query()->pluck('id')->all();
        $jumlah = [];

        foreach ($event as $satu) {
            $tercakup = $satu->berlakuUntukSemuaUnit()
                ? $semuaUnitId
                : UnitKerja::idsDenganTurunan($satu->unitKerja->pluck('id')->all());

            foreach ($tercakup as $unitId) {
                $jumlah[$unitId] = ($jumlah[$unitId] ?? 0) + 1;
            }
        }

        return $jumlah;
    }

    /**
     * Jumlah hari kerja KALENDER bagi sebuah unit dalam rentang tanggal.
     *
     * Dibatasi rentang wajar yang sama dengan FilterLaporanRequest (maksimum
     * 366 hari), sehingga iterasinya selalu terbatas. Setiap panggilan
     * `hariKerja()` sudah memoized di dalam KalenderKerjaService sendiri.
     */
    protected function hariKerjaDalamRentang(int $unitKerjaId, Carbon $dari, Carbon $sampai): int
    {
        $jumlah = 0;
        $tanggal = $dari->copy();

        while ($tanggal->lessThanOrEqualTo($sampai)) {
            if ($this->kalender->hariKerja($unitKerjaId, $tanggal)) {
                $jumlah++;
            }

            $tanggal->addDay();
        }

        return $jumlah;
    }

    /**
     * @param  array<int, array<string, mixed>>  $perUnit
     * @return array<string, mixed>
     */
    protected function totalDari(array $perUnit): array
    {
        $hadir = array_sum(array_column($perUnit, 'hadir'));
        $terlambat = array_sum(array_column($perUnit, 'terlambat'));
        $totalEkspektasi = array_sum(array_column($perUnit, 'total_ekspektasi'));

        return [
            'pegawai' => array_sum(array_column($perUnit, 'pegawai')),
            'hadir' => $hadir,
            'tepat' => max(0, $hadir - $terlambat),
            'terlambat' => $terlambat,
            'tanpa_keterangan' => array_sum(array_column($perUnit, 'tanpa_keterangan')),
            'total_ekspektasi' => $totalEkspektasi,
            'tingkat_kehadiran' => $totalEkspektasi > 0 ? round($hadir / $totalEkspektasi * 100, 1) : null,
        ];
    }

    /**
     * Periode pembanding: bulan kalender sebelumnya bila periode yang dipilih
     * persis satu bulan penuh, selebihnya rentang berpanjang sama tepat
     * sebelum tanggal mulai.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function periodePembanding(Carbon $dari, Carbon $sampai): array
    {
        $bulanPenuh = $dari->day === 1 && $sampai->isSameDay($dari->copy()->endOfMonth()->startOfDay());

        if ($bulanPenuh) {
            $dariPembanding = $dari->copy()->subMonthNoOverflow()->startOfMonth();

            return [$dariPembanding, $dariPembanding->copy()->endOfMonth()->startOfDay()];
        }

        $panjangHari = $dari->diffInDays($sampai) + 1;
        $sampaiPembanding = $dari->copy()->subDay();

        return [$sampaiPembanding->copy()->subDays($panjangHari - 1), $sampaiPembanding];
    }

    /**
     * Kalimat kesimpulan — templat, bukan bebas (FR-LAP-04). Tiga bentuk
     * tergantung ketersediaan data, supaya tidak ada yang dibagi dengan nol
     * atau dibiarkan kosong tanpa penjelasan.
     */
    protected function kesimpulan(
        Carbon $dari,
        Carbon $sampai,
        array $total,
        Carbon $dariPembanding,
        Carbon $sampaiPembanding,
        array $totalPembanding,
    ): string {
        $labelPeriode = $this->labelPeriode($dari, $sampai);

        if ($total['total_ekspektasi'] === 0) {
            return "Tidak terdapat kegiatan maupun hari kerja yang tercatat pada periode {$labelPeriode}, sehingga tingkat kehadiran belum dapat dihitung.";
        }

        $persenSekarang = $this->formatPersen($total['tingkat_kehadiran']);

        if ($totalPembanding['total_ekspektasi'] === 0) {
            return "Tingkat kehadiran pada periode {$labelPeriode} mencapai {$persenSekarang}%. Belum tersedia data pembanding pada periode sebelumnya.";
        }

        $persenSebelumnya = $this->formatPersen($totalPembanding['tingkat_kehadiran']);
        $labelPembanding = $this->labelPeriode($dariPembanding, $sampaiPembanding);
        $selisih = round($total['tingkat_kehadiran'] - $totalPembanding['tingkat_kehadiran'], 1);

        if ($selisih === 0.0) {
            return "Tingkat kehadiran pada periode {$labelPeriode} mencapai {$persenSekarang}%, setara dengan periode {$labelPembanding} yang juga tercatat {$persenSebelumnya}%.";
        }

        $arah = $selisih > 0 ? 'naik' : 'turun';

        return "Tingkat kehadiran pada periode {$labelPeriode} mencapai {$persenSekarang}%, {$arah} "
            .$this->formatPersen(abs($selisih))
            ."% dibanding periode {$labelPembanding} yang tercatat {$persenSebelumnya}%.";
    }

    /**
     * Rekomendasi — dua kelompok terpisah, bukan satu kalimat gabungan per
     * unit: unit yang kena kedua ambang muncul di kedua kelompok, masing-
     * masing dengan kalimatnya sendiri (FR-LAP-04).
     *
     * @param  array<int, array<string, mixed>>  $perUnit
     * @return array{kehadiran_rendah: array<int, array<string, string>>, keterlambatan_tinggi: array<int, array<string, string>>, tidak_ada_masalah: bool}
     */
    protected function rekomendasi(array $perUnit): array
    {
        $ambang = $this->setting->ambil();
        $ambangHadir = $ambang['ambang_kehadiran_minimum'];
        $ambangTelat = $ambang['ambang_keterlambatan_maksimum'];

        $kehadiranRendah = collect($perUnit)
            ->filter(fn (array $u) => $u['tingkat_kehadiran'] !== null && $u['tingkat_kehadiran'] < $ambangHadir)
            ->sortBy('tingkat_kehadiran')
            ->map(fn (array $u) => [
                'unit' => $u['nama'],
                'kalimat' => sprintf(
                    'Unit %s mencatat tingkat kehadiran %s%%, di bawah ambang batas %d%%. Diperlukan tindak lanjut berupa evaluasi kehadiran pegawai di unit ini.',
                    $u['nama'],
                    $this->formatPersen($u['tingkat_kehadiran']),
                    $ambangHadir,
                ),
            ])
            ->values()
            ->all();

        $keterlambatanTinggi = collect($perUnit)
            ->filter(fn (array $u) => $u['tingkat_keterlambatan'] !== null && $u['tingkat_keterlambatan'] > $ambangTelat)
            ->sortByDesc('tingkat_keterlambatan')
            ->map(fn (array $u) => [
                'unit' => $u['nama'],
                'kalimat' => sprintf(
                    'Unit %s mencatat tingkat keterlambatan %s%% dari total kehadiran, melampaui ambang batas %d%%. Disarankan peninjauan kembali kedisiplinan waktu kedatangan pegawai di unit ini.',
                    $u['nama'],
                    $this->formatPersen($u['tingkat_keterlambatan']),
                    $ambangTelat,
                ),
            ])
            ->values()
            ->all();

        return [
            'kehadiran_rendah' => $kehadiranRendah,
            'keterlambatan_tinggi' => $keterlambatanTinggi,
            'tidak_ada_masalah' => $kehadiranRendah === [] && $keterlambatanTinggi === [],
        ];
    }

    protected function namaCakupan(User $pelaku, ?int $unitKerjaId): string
    {
        if ($unitKerjaId !== null) {
            return UnitKerja::query()->whereKey($unitKerjaId)->value('nama') ?? 'Tidak diketahui';
        }

        return $pelaku->lintasUnit() ? 'Seluruh Unit Kerja' : ($pelaku->unitKerja?->nama ?? 'Tanpa unit kerja');
    }

    /**
     * "1–30 September 2026", atau bentuk lain bila lintas bulan/tahun.
     */
    protected function labelPeriode(Carbon $dari, Carbon $sampai): string
    {
        if ($dari->isSameDay($sampai)) {
            return $dari->translatedFormat('d F Y');
        }

        if ($dari->isSameMonth($sampai) && $dari->year === $sampai->year) {
            return $dari->day.'–'.$sampai->translatedFormat('j F Y');
        }

        if ($dari->year === $sampai->year) {
            return $dari->translatedFormat('j F').' – '.$sampai->translatedFormat('j F Y');
        }

        return $dari->translatedFormat('j F Y').' – '.$sampai->translatedFormat('j F Y');
    }

    /**
     * Format persentase dengan koma desimal, mengikuti konvensi Indonesia.
     * null tampil sebagai tanda hubung — unit yang dikecualikan dari
     * evaluasi ambang, bukan unit yang kebetulan bernilai nol.
     */
    public static function formatPersen(?float $nilai): string
    {
        return $nilai === null ? '—' : number_format($nilai, 1, ',', '.');
    }
}
