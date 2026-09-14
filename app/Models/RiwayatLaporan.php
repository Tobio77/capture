<?php

namespace App\Models;

use App\Enums\StatusRiwayatLaporan;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu permintaan Generate Laporan Resmi, dari antre sampai selesai/gagal.
 *
 * @property int $id
 * @property int $user_id
 * @property string $format
 * @property Carbon $dari
 * @property Carbon $sampai
 * @property int|null $unit_kerja_id
 * @property StatusRiwayatLaporan $status
 * @property string $nama_berkas
 * @property string|null $path
 * @property string|null $pesan_galat
 * @property Carbon|null $selesai_pada
 */
#[Fillable([
    'user_id',
    'format',
    'dari',
    'sampai',
    'unit_kerja_id',
    'status',
    'nama_berkas',
    'path',
    'pesan_galat',
    'selesai_pada',
])]
class RiwayatLaporan extends Model
{
    protected $table = 'riwayat_laporan';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dari' => 'date',
            'sampai' => 'date',
            'status' => StatusRiwayatLaporan::class,
            'selesai_pada' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<UnitKerja, $this> */
    public function unitKerja(): BelongsTo
    {
        return $this->belongsTo(UnitKerja::class);
    }

    public function selesai(): bool
    {
        return $this->status === StatusRiwayatLaporan::Selesai;
    }
}
