<?php

namespace App\Models;

use App\Enums\AksiLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Audit trail: siapa, kapan, aksi apa (FR-AUTH-03).
 * Bersifat append-only — tidak pernah diubah atau dihapus dari aplikasi.
 */
#[Fillable([
    'user_id',
    'kiosk_id',
    'aksi',
    'deskripsi',
    'subjek_type',
    'subjek_id',
    'ip_address',
    'user_agent',
])]
class LogAktivitas extends Model
{
    protected $table = 'log_aktivitas';

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aksi' => AksiLog::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Kiosk, $this> */
    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(Kiosk::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subjek(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<LogAktivitas>  $query
     */
    public function scopeAksi(Builder $query, AksiLog ...$aksi): void
    {
        $query->whereIn('aksi', array_column($aksi, 'value'));
    }

    /**
     * @param  Builder<LogAktivitas>  $query
     */
    public function scopeTerbaru(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
