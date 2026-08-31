<?php

namespace App\Models;

use Database\Factories\PegawaiFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Data induk pegawai. Bersifat baca-saja di SI-ABSEN — perubahan dilakukan
 * di WORKA/BKD lalu masuk melalui sinkronisasi (FR-PEG-02).
 */
#[Fillable([
    'nip',
    'nama',
    'unit_kerja_id',
    'jabatan',
    'aktif',
    'foto_referensi_path',
    'foto_tersedia_worka',
    'wajah_terdaftar',
    'sumber_sinkron_terakhir',
])]
class Pegawai extends Model
{
    /** @use HasFactory<PegawaiFactory> */
    use HasFactory;

    protected $table = 'pegawai';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
            'foto_tersedia_worka' => 'boolean',
            'wajah_terdaftar' => 'boolean',
            'sumber_sinkron_terakhir' => 'datetime',
        ];
    }

    /** @return BelongsTo<UnitKerja, $this> */
    public function unitKerja(): BelongsTo
    {
        return $this->belongsTo(UnitKerja::class);
    }

    /**
     * @param  Builder<Pegawai>  $query
     */
    public function scopeAktif(Builder $query): void
    {
        $query->where('aktif', true);
    }

    /**
     * Pencarian berdasarkan nama atau NIP (FR-PEG-04).
     *
     * @param  Builder<Pegawai>  $query
     */
    public function scopeCari(Builder $query, ?string $kataKunci): void
    {
        $query->when($kataKunci, function (Builder $query, string $kataKunci) {
            $query->where(function (Builder $query) use ($kataKunci) {
                $query->where('nama', 'like', "%{$kataKunci}%")
                    ->orWhere('nip', 'like', "%{$kataKunci}%");
            });
        });
    }
}
