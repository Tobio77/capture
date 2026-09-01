<?php

namespace App\Services;

use App\Enums\JenisAbsen;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan kehadiran untuk Dashboard (FR-DASH-01, FR-DASH-02).
 *
 * Seluruh angka mengikuti cakupan peran: Admin UPT hanya melihat unitnya
 * beserta seluruh turunannya, sedangkan peran lintas unit melihat semuanya
 * (SRS §6).
 */
class DashboardService
{
    /** Panjang rentang grafik tren kehadiran. */
    public const int HARI_TREN = 7;

    /**
     * @return array<string, mixed>
     */
    public function statistik(User $pelaku): array
    {
        $cakupan = $this->cakupan($pelaku);
        $hariIni = Carbon::today();

        $totalPegawai = $this->pegawaiAktif($cakupan)->count();
        $hadir = $this->hadirPada($hariIni, $cakupan);

        return [
            'total_pegawai' => $totalPegawai,
            'kiosk_aktif' => $this->kioskAktifPada($hariIni, $cakupan),
            'event_berlangsung' => $this->eventBerlangsung($pelaku),
            'hadir_hari_ini' => $hadir,

            /*
             * Persentase dihitung terhadap pegawai aktif dalam cakupan, bukan
             * terhadap peserta event: yang ingin diketahui pimpinan adalah
             * berapa bagian pegawai yang hadir, bukan berapa bagian undangan.
             */
            'persentase_kehadiran' => $totalPegawai === 0
                ? 0.0
                : round($hadir / $totalPegawai * 100, 1),
        ];
    }

    /**
     * Jumlah pegawai hadir per hari selama {@see self::HARI_TREN} hari
     * terakhir, termasuk hari ini (FR-DASH-02).
     *
     * @return array<int, array<string, mixed>>
     */
    public function tren(User $pelaku): array
    {
        $cakupan = $this->cakupan($pelaku);
        $mulai = Carbon::today()->subDays(self::HARI_TREN - 1);

        /*
         * Dihitung dari pegawai berbeda, bukan baris absensi: seorang pegawai
         * yang datang lalu pulang menghasilkan dua baris tetapi tetap satu
         * kehadiran.
         */
        $perHari = Absensi::query()
            ->where('jenis', JenisAbsen::Datang)
            ->whereDate('waktu', '>=', $mulai)
            ->when($cakupan !== null, fn ($q) => $q->whereIn(
                'pegawai_id',
                $this->pegawaiAktif($cakupan)->select('id'),
            ))
            ->selectRaw('date(waktu) as tanggal, count(distinct pegawai_id) as jumlah')
            ->groupBy('tanggal')
            ->pluck('jumlah', 'tanggal');

        $hasil = [];

        for ($i = 0; $i < self::HARI_TREN; $i++) {
            $hari = $mulai->copy()->addDays($i);
            $kunci = $hari->toDateString();

            $hasil[] = [
                'tanggal' => $kunci,
                'label' => $hari->translatedFormat('D'),
                'jumlah' => (int) ($perHari[$kunci] ?? 0),
            ];
        }

        return $hasil;
    }

    /**
     * Id unit kerja yang boleh dilihat pengguna, atau null untuk peran lintas
     * unit yang tidak perlu disaring sama sekali.
     *
     * @return array<int, int>|null
     */
    protected function cakupan(User $pelaku): ?array
    {
        return $pelaku->lintasUnit()
            ? null
            : UnitKerja::idsDenganTurunan($pelaku->unit_kerja_id);
    }

    /**
     * @param  array<int, int>|null  $cakupan
     * @return Builder<Pegawai>
     */
    protected function pegawaiAktif(?array $cakupan)
    {
        return Pegawai::query()
            ->where('aktif', true)
            ->when($cakupan !== null, fn ($q) => $q->whereIn('unit_kerja_id', $cakupan));
    }

    /**
     * @param  array<int, int>|null  $cakupan
     */
    protected function hadirPada(Carbon $hari, ?array $cakupan): int
    {
        return Absensi::query()
            ->where('jenis', JenisAbsen::Datang)
            ->whereDate('waktu', $hari)
            ->when($cakupan !== null, fn ($q) => $q->whereIn(
                'pegawai_id',
                $this->pegawaiAktif($cakupan)->select('id'),
            ))
            ->distinct()
            ->count('pegawai_id');
    }

    /**
     * Kiosk yang benar-benar melayani event pada hari itu — bukan sekadar
     * perangkat terdaftar (FR-DASH-01).
     *
     * @param  array<int, int>|null  $cakupan
     */
    protected function kioskAktifPada(Carbon $hari, ?array $cakupan): int
    {
        return DB::table('event_kiosk')
            ->whereDate('terakhir_aktif_pada', $hari)
            ->when($cakupan !== null, fn ($q) => $q->whereIn(
                'kiosk_id',
                Kiosk::query()->whereIn('unit_kerja_id', $cakupan)->select('id'),
            ))
            ->distinct()
            ->count('kiosk_id');
    }

    /**
     * Event yang entry-nya masih dibuka dan menyentuh cakupan pengguna.
     */
    protected function eventBerlangsung(User $pelaku): int
    {
        return EventAbsen::query()
            ->aktif()
            ->when(
                ! $pelaku->lintasUnit(),
                fn ($q) => $q->menyentuhUnit(UnitKerja::idsDenganTurunan($pelaku->unit_kerja_id)),
            )
            ->count();
    }
}
