<?php

namespace App\Models;

use App\Enums\CakupanEvent;
use App\Enums\StatusEvent;
use Database\Factories\EventAbsenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Event absensi (FR-EVT-01 s.d. FR-EVT-06).
 *
 * Event tidak pernah dihapus, hanya ditutup — data absensi menautnya.
 */
#[Fillable([
    'nama',
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
     * @param  Builder<EventAbsen>  $query
     */
    public function scopeAktif(Builder $query): void
    {
        $query->where('status', StatusEvent::Aktif);
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
