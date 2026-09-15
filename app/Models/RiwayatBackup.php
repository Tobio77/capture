<?php

namespace App\Models;

use App\Enums\StatusRiwayatLaporan;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu proses backup data absensi, dari antre sampai selesai/gagal.
 *
 * Status memakai {@see StatusRiwayatLaporan} apa adanya — dipakai ulang,
 * bukan enum baru: kedua fitur ini sama-sama antre/diproses/selesai/gagal,
 * dan tidak ada keadaan tambahan yang khas backup.
 *
 * @property int $id
 * @property string $dipicu_oleh
 * @property int|null $user_id
 * @property StatusRiwayatLaporan $status
 * @property string $nama_berkas
 * @property string|null $path
 * @property int|null $ukuran_bytes
 * @property string|null $pesan_galat
 * @property Carbon|null $selesai_pada
 */
#[Fillable([
    'dipicu_oleh',
    'user_id',
    'status',
    'nama_berkas',
    'path',
    'ukuran_bytes',
    'pesan_galat',
    'selesai_pada',
])]
class RiwayatBackup extends Model
{
    protected $table = 'riwayat_backup';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StatusRiwayatLaporan::class,
            'selesai_pada' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function selesai(): bool
    {
        return $this->status === StatusRiwayatLaporan::Selesai;
    }
}
