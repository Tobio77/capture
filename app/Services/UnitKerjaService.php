<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pengelolaan unit kerja peserta SI-ABSEN (FR-UNIT-01, FR-UNIT-02).
 */
class UnitKerjaService
{
    public function __construct(protected LogAktivitasService $log) {}

    /**
     * Daftar unit kerja level teratas beserta jumlah pegawai dan kiosk
     * terdaftar (FR-UNIT-02).
     *
     * Yang ditampilkan hanya unit level teratas (lihat
     * {@see UnitKerja::scopeLevelTeratas()}), tetapi jumlah pegawai dan kiosk
     * dihitung atas seluruh turunannya — pegawai menaut ke seksi/subbag,
     * sehingga menghitung unit itu sendiri saja akan menampilkan angka nol
     * pada UPT yang sebenarnya berisi ratusan orang.
     *
     * Admin UPT hanya melihat unit level teratas yang menaungi dirinya.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function daftar(User $pelaku): Collection
    {
        $teratas = UnitKerja::query()->levelTeratas()->orderBy('kode')->get();
        $pegawaiPerUnit = $this->jumlahPer(Pegawai::query());
        $kioskPerUnit = $this->jumlahPer(Kiosk::query());

        return $teratas
            ->map(function (UnitKerja $unit) use ($pegawaiPerUnit, $kioskPerUnit) {
                $cakupan = UnitKerja::idsDenganTurunan($unit->id);

                return [
                    'id' => $unit->id,
                    'kode' => $unit->kode,
                    'nama' => $unit->nama,
                    'aktif' => $unit->aktif,
                    'jumlah_pegawai' => $this->jumlahDalam($pegawaiPerUnit, $cakupan),
                    'jumlah_kiosk' => $this->jumlahDalam($kioskPerUnit, $cakupan),
                    'jumlah_unit_turunan' => count($cakupan) - 1,
                    'cakupan' => $cakupan,
                ];
            })
            ->when(
                ! $pelaku->lintasUnit(),
                fn (Collection $daftar) => $daftar->filter(
                    fn (array $unit) => in_array($pelaku->unit_kerja_id, $unit['cakupan'], true),
                ),
            )
            ->map(fn (array $unit) => Arr::except($unit, 'cakupan'))
            ->values();
    }

    /**
     * Jumlah baris per unit_kerja_id dalam satu kali agregasi.
     *
     * @param  Builder<Pegawai>|Builder<Kiosk>  $query
     * @return array<int, int>
     */
    protected function jumlahPer(Builder $query): array
    {
        return $query
            ->selectRaw('unit_kerja_id, count(*) as jumlah')
            ->groupBy('unit_kerja_id')
            ->pluck('jumlah', 'unit_kerja_id')
            ->all();
    }

    /**
     * @param  array<int, int>  $jumlahPerUnit
     * @param  array<int, int>  $cakupan
     */
    protected function jumlahDalam(array $jumlahPerUnit, array $cakupan): int
    {
        $jumlah = 0;

        foreach ($cakupan as $id) {
            $jumlah += $jumlahPerUnit[$id] ?? 0;
        }

        return $jumlah;
    }

    /**
     * @param  array{kode: string, nama: string}  $data
     */
    public function buat(array $data, User $pelaku): UnitKerja
    {
        $unitKerja = UnitKerja::create([
            'kode' => self::normalkanKode($data['kode']),
            'nama' => $data['nama'],

            // Unit buatan admin dibuat sebagai unit level teratas, yaitu anak
            // simpul OPD — tanpa ini unit baru langsung hilang dari daftar
            // karena tidak lolos scopeLevelTeratas().
            'induk_id' => UnitKerja::idOpd(),
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
