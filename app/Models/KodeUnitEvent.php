<?php

namespace App\Models;

use Database\Factories\KodeUnitEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kode yang dipakai perangkat absen untuk bergabung ke sebuah event
 * (FR-EVT-03, revisi S29).
 *
 * Satu baris per pasangan event × unit kerja. Kodenya disimpan apa adanya,
 * bukan sebagai hash: berbeda dari `kiosk.device_token`, kode ini harus dapat
 * dibaca ulang admin untuk dibacakan kepada petugas di ruangan lain, dan masa
 * hidupnya hanya selama event berlangsung.
 */
#[Fillable([
    'event_absen_id',
    'unit_kerja_id',
    'kode',
    'direset_oleh',
    'direset_pada',
])]
class KodeUnitEvent extends Model
{
    /** @use HasFactory<KodeUnitEventFactory> */
    use HasFactory;

    protected $table = 'event_kode_unit';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direset_pada' => 'datetime',
        ];
    }

    /** @return BelongsTo<EventAbsen, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(EventAbsen::class, 'event_absen_id');
    }

    /** @return BelongsTo<UnitKerja, $this> */
    public function unitKerja(): BelongsTo
    {
        return $this->belongsTo(UnitKerja::class);
    }

    /** @return BelongsTo<User, $this> */
    public function pelakuReset(): BelongsTo
    {
        return $this->belongsTo(User::class, 'direset_oleh');
    }
}
