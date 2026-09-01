<?php

namespace App\Models;

use App\Enums\JenisAbsen;
use App\Enums\MetodeAbsen;
use App\Enums\StatusKetepatan;
use Database\Factories\AbsensiFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan kehadiran seorang pegawai pada sebuah event (FR-TAP-05).
 */
#[Fillable([
    'event_absen_id',
    'pegawai_id',
    'kiosk_id',
    'jenis',
    'metode',
    'waktu',
    'status_ketepatan',
    'skor_kecocokan_wajah',
    'foto_path',
])]
class Absensi extends Model
{
    /** @use HasFactory<AbsensiFactory> */
    use HasFactory;

    protected $table = 'absensi';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jenis' => JenisAbsen::class,
            'metode' => MetodeAbsen::class,
            'waktu' => 'datetime',
            'status_ketepatan' => StatusKetepatan::class,
            'skor_kecocokan_wajah' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<EventAbsen, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(EventAbsen::class, 'event_absen_id');
    }

    /** @return BelongsTo<Pegawai, $this> */
    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class);
    }

    /** @return BelongsTo<Kiosk, $this> */
    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(Kiosk::class);
    }
}
