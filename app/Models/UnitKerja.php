<?php

namespace App\Models;

use Database\Factories\UnitKerjaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['kode', 'nama', 'aktif'])]
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
}
