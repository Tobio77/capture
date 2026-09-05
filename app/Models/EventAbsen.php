<?php

namespace App\Models;

use App\Enums\CakupanEvent;
use App\Enums\JenisEvent;
use App\Enums\StatusEvent;
use Database\Factories\EventAbsenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Event absensi (FR-EVT-01 s.d. FR-EVT-06).
 *
 * Event tidak pernah dihapus, hanya ditutup — data absensi menautnya.
 */
#[Fillable([
    'nama',
    'jenis',
    'kunci_sesi',
    'tanggal',
    'jam_mulai',
    'toleransi_menit',
    'cakupan',
    'status',
    'dibuat_oleh',
    'ditutup_pada',
    'catatan',
])]
class EventAbsen extends Model
{
    /** @use HasFactory<EventAbsenFactory> */
    use HasFactory;

    protected $table = 'event_absen';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'toleransi_menit' => 'integer',
            'jenis' => JenisEvent::class,
            'cakupan' => CakupanEvent::class,
            'status' => StatusEvent::class,
            'ditutup_pada' => 'datetime',
        ];
    }

    /** @return BelongsToMany<UnitKerja, $this> */
    public function unitKerja(): BelongsToMany
    {
        return $this->belongsToMany(UnitKerja::class, 'event_unit_kerja');
    }

    /**
     * Perangkat absen yang BERGABUNG ke event ini lewat kode unit kerja
     * (FR-EVT-03, revisi S29).
     *
     * Relasi ini bukan sekadar riwayat: sejak kode unit kerja diperkenalkan,
     * keanggotaan di sinilah yang menentukan boleh-tidaknya sebuah perangkat
     * membuka layar Absen Event. Perangkat yang unitnya tercakup namun belum
     * mengetikkan kode tidak muncul di sini, dan karenanya tidak melayani
     * event ini.
     *
     * @return BelongsToMany<Kiosk, $this>
     */
    public function kiosk(): BelongsToMany
    {
        return $this->belongsToMany(Kiosk::class, 'event_kiosk')
            ->withPivot([
                'unit_kerja_id',
                'ip_address',
                'aktif_pada',
                'bergabung_pada',
                'terakhir_aktif_pada',
            ]);
    }

    /**
     * Kode yang dipakai perangkat untuk bergabung — satu per unit kerja dalam
     * cakupan event (FR-EVT-03).
     *
     * @return HasMany<KodeUnitEvent, $this>
     */
    public function kodeUnit(): HasMany
    {
        return $this->hasMany(KodeUnitEvent::class, 'event_absen_id');
    }

    /** @return HasMany<Absensi, $this> */
    public function absensi(): HasMany
    {
        return $this->hasMany(Absensi::class);
    }

    /** @return BelongsTo<User, $this> */
    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function aktif(): bool
    {
        return $this->status === StatusEvent::Aktif;
    }

    public function berlakuUntukSemuaUnit(): bool
    {
        return $this->cakupan === CakupanEvent::SemuaUnit;
    }

    /**
     * Sesi absen harian yang dibuka sistem, bukan kegiatan buatan admin.
     */
    public function absenUmum(): bool
    {
        return $this->jenis === JenisEvent::Umum;
    }

    /**
     * @param  Builder<EventAbsen>  $query
     */
    public function scopeAktif(Builder $query): void
    {
        $query->where('status', StatusEvent::Aktif);
    }

    /**
     * Hanya event kegiatan — sesi absen umum punya menunya sendiri dan tidak
     * ikut memenuhi Daftar Event maupun pemilih Rekap Absen.
     *
     * @param  Builder<EventAbsen>  $query
     */
    public function scopeKegiatan(Builder $query): void
    {
        $query->where('jenis', JenisEvent::Kegiatan);
    }

    /**
     * @param  Builder<EventAbsen>  $query
     */
    public function scopeUmum(Builder $query): void
    {
        $query->where('jenis', JenisEvent::Umum);
    }

    /**
     * Event yang menyentuh salah satu unit kerja pada daftar.
     *
     * Event bercakupan "semua unit" selalu ikut, karena secara definisi
     * mencakup unit mana pun (FR-EVT-01).
     *
     * @param  Builder<EventAbsen>  $query
     * @param  array<int, int>  $unitKerjaIds
     */
    public function scopeMenyentuhUnit(Builder $query, array $unitKerjaIds): void
    {
        $query->where(function (Builder $q) use ($unitKerjaIds) {
            $q->where('cakupan', CakupanEvent::SemuaUnit)
                ->orWhereHas(
                    'unitKerja',
                    fn (Builder $unit) => $unit->whereIn('unit_kerja.id', $unitKerjaIds),
                );
        });
    }
}
