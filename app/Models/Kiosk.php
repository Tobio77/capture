<?php

namespace App\Models;

use App\Enums\StatusKiosk;
use App\Enums\SumberKiosk;
use Database\Factories\KioskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Akun perangkat titik absen — bukan akun pegawai.
 *
 * Kolom `device_token` menyimpan hash SHA-256 dari token, bukan token mentahnya.
 * Token mentah hanya ada satu kali (saat aktivasi) dan disimpan di cookie perangkat.
 */
#[Fillable([
    'nama_titik',
    'sumber',
    'unit_kerja_id',
    'device_token',
    'kode_aktivasi',
    'kode_aktivasi_kedaluwarsa_at',
    'ip_terakhir',
    'status',
    'login_terakhir_at',
    'diaktifkan_pada',
    'aktif',
])]
#[Hidden(['device_token', 'kode_aktivasi'])]
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
            'sumber' => SumberKiosk::class,
            'status' => StatusKiosk::class,
            'login_terakhir_at' => 'datetime',
            'kode_aktivasi_kedaluwarsa_at' => 'datetime',
            'diaktifkan_pada' => 'datetime',
            'aktif' => 'boolean',
        ];
    }

    /** @return BelongsTo<UnitKerja, $this> */
    public function unitKerja(): BelongsTo
    {
        return $this->belongsTo(UnitKerja::class);
    }

    /**
     * Perangkat sudah memegang device_token.
     *
     * @return Attribute<bool, never>
     */
    protected function sudahDiaktifkan(): Attribute
    {
        return Attribute::get(fn (): bool => $this->device_token !== null);
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function kodeAktivasiKedaluwarsa(): Attribute
    {
        return Attribute::get(fn (): bool => $this->kode_aktivasi_kedaluwarsa_at?->isPast() ?? true);
    }

    /**
     * @param  Builder<Kiosk>  $query
     */
    public function scopeAktif(Builder $query): void
    {
        $query->where('aktif', true);
    }

    /**
     * @param  Builder<Kiosk>  $query
     */
    public function scopeMenungguAktivasi(Builder $query): void
    {
        $query->whereNull('device_token');
    }
}
