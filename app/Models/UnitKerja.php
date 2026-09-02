<?php

namespace App\Models;

use Database\Factories\UnitKerjaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['kode', 'nama', 'induk_id', 'aktif'])]
class UnitKerja extends Model
{
    /** @use HasFactory<UnitKerjaFactory> */
    use HasFactory;

    protected $table = 'unit_kerja';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
        ];
    }

    /**
     * Unit induk sesuai hirarki WORKA — null pada unit puncak.
     *
     * @return BelongsTo<UnitKerja, $this>
     */
    public function induk(): BelongsTo
    {
        return $this->belongsTo(UnitKerja::class, 'induk_id');
    }

    /** @return HasMany<UnitKerja, $this> */
    public function anak(): HasMany
    {
        return $this->hasMany(UnitKerja::class, 'induk_id');
    }

    /** @return HasMany<Pegawai, $this> */
    public function pegawai(): HasMany
    {
        return $this->hasMany(Pegawai::class);
    }

    /** @return HasMany<Kiosk, $this> */
    public function kiosk(): HasMany
    {
        return $this->hasMany(Kiosk::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @param  Builder<UnitKerja>  $query
     */
    public function scopeAktif(Builder $query): void
    {
        $query->where('aktif', true);
    }

    /**
     * Unit kerja "level teratas": anak langsung simpul OPD pada hirarki WORKA
     * — DISNAKER, tiap UPT, tiap bidang, dan sekretariat.
     *
     * Inilah satuan yang dikelola admin dan dipilih pada event maupun kiosk.
     * Seksi/subbag di bawahnya sengaja tidak diekspos: absensi diselenggarakan
     * pada tingkat UPT/bidang, sementara turunannya tetap ikut terhitung lewat
     * {@see self::idsDenganTurunan()}.
     *
     * Selama WORKA belum pernah disinkronkan, simpul OPD belum ada; pada
     * keadaan itu unit tanpa induk yang dianggap level teratas, supaya
     * halaman Setting Unit Kerja tidak tampil kosong di instalasi baru.
     *
     * @param  Builder<UnitKerja>  $query
     */
    public function scopeLevelTeratas(Builder $query): void
    {
        $opd = static::idOpd();

        $opd === null
            ? $query->whereNull('induk_id')
            : $query->where('induk_id', $opd);
    }

    /**
     * Id simpul OPD pada hirarki WORKA, atau null bila WORKA belum pernah
     * disinkronkan.
     */
    public static function idOpd(): ?int
    {
        $id = static::query()
            ->where('kode', config('services.worka.kode_opd'))
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Unit level teratas yang menaungi sebuah unit kerja, atau null bila
     * tidak ada yang menaunginya.
     *
     * Pegawai dan perangkat menaut ke seksi/subbag, sedangkan yang menjadi
     * satuan kerja sesungguhnya — pemilik sesi absen umum, cakupan Admin UPT
     * — adalah UPT atau bidang di atasnya.
     */
    public static function idTeratasUntuk(?int $unitKerjaId): ?int
    {
        if ($unitKerjaId === null) {
            return null;
        }

        foreach (static::query()->levelTeratas()->pluck('id') as $id) {
            if (in_array($unitKerjaId, static::idsDenganTurunan((int) $id), true)) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * Id seluruh unit level teratas yang menaungi sebuah unit kerja.
     *
     * Bentuk jamak dari {@see self::idTeratasUntuk()}, dipakai penyaring
     * kueri yang membutuhkan klausa `whereIn`.
     *
     * @return array<int, int>
     */
    public static function idTeratasMenaungi(?int $unitKerjaId): array
    {
        $id = static::idTeratasUntuk($unitKerjaId);

        return $id === null ? [] : [$id];
    }

    /**
     * Id sebuah unit beserta seluruh turunannya, sedalam apa pun.
     *
     * Dipakai setiap kali pertanyaannya "siapa saja yang bernaung di unit ini"
     * — daftar pegawai, cakupan admin UPT, dan peserta event — karena pegawai
     * menaut ke seksi/subbag, bukan ke UPT-nya.
     *
     * @param  int|array<int, int>  $akar
     * @return array<int, int>
     */
    public static function idsDenganTurunan(int|array $akar): array
    {
        $anakPerInduk = static::anakPerInduk();
        $hasil = [];
        $antrian = array_values((array) $akar);

        while ($antrian !== []) {
            $id = array_shift($antrian);

            // Penjaga siklus: FK tidak mencegah A → B → A, dan satu baris
            // rusak tidak boleh membuat permintaan berputar selamanya.
            if (isset($hasil[$id])) {
                continue;
            }

            $hasil[$id] = $id;

            foreach ($anakPerInduk[$id] ?? [] as $anak) {
                $antrian[] = $anak;
            }
        }

        return array_values($hasil);
    }

    /**
     * Peta induk → daftar id anak langsung, dari satu kali baca tabel.
     *
     * @return array<int, array<int, int>>
     */
    protected static function anakPerInduk(): array
    {
        $anakPerInduk = [];

        foreach (static::query()->pluck('induk_id', 'id') as $id => $indukId) {
            if ($indukId !== null) {
                $anakPerInduk[(int) $indukId][] = (int) $id;
            }
        }

        return $anakPerInduk;
    }
}
