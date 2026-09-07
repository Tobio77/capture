<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tanggal yang bukan hari kerja (FR-SET-08).
 *
 * `unit_kerja_id` null berarti berlaku bagi SELURUH unit — libur nasional.
 * Diisi berarti khusus unit itu beserta turunannya, misalnya hari ulang tahun
 * sebuah UPT atau kegiatan yang meliburkan satu balai saja.
 *
 * Diisi admin, tidak ditarik dari layanan luar. Jaringan dinas kerap berada di
 * belakang proxy yang menyaring keluar, dan kalender yang gagal diam-diam
 * lebih buruk daripada kalender yang diisi tangan: yang pertama membuat
 * seluruh rekap salah tanpa ada yang tahu sebabnya.
 */
#[Fillable(['tanggal', 'keterangan', 'unit_kerja_id', 'dibuat_oleh'])]
class HariLibur extends Model
{
    protected $table = 'hari_libur';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
        ];
    }

    /** @return BelongsTo<UnitKerja, $this> */
    public function unitKerja(): BelongsTo
    {
        return $this->belongsTo(UnitKerja::class);
    }

    /** @return BelongsTo<User, $this> */
    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    /** Libur nasional berlaku bagi semua unit. */
    public function nasional(): bool
    {
        return $this->unit_kerja_id === null;
    }
}
