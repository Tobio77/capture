<?php

namespace App\Models;

use App\Enums\StatusKiosk;
use Database\Factories\KioskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Akun perangkat titik absen — bukan akun pegawai.
 * Autentikasi memakai device_token per perangkat.
 */
#[Fillable([
    'nama_titik',
    'unit_kerja_id',
    'device_token',
    'ip_terakhir',
    'status',
    'login_terakhir_at',
    'aktif',
])]
#[Hidden(['device_token'])]
class Kiosk extends Model
{
    /** @use HasFactory<KioskFactory> */
    use HasFactory;

    protected $table = 'kiosk';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StatusKiosk::class,
            'login_terakhir_at' => 'datetime',
            'aktif' => 'boolean',
        ];
    }

    /** @return BelongsTo<UnitKerja, $this> */
    public function unitKerja(): BelongsTo
    {
        return $this->belongsTo(UnitKerja::class);
    }

    /**
     * @param  Builder<Kiosk>  $query
     */
    public function scopeAktif(Builder $query): void
    {
        $query->where('aktif', true);
    }
}
