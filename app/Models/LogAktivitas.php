<?php

namespace App\Models;

use App\Enums\AksiLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

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
     * Append-only, ditegakkan — bukan sekadar disepakati (perbaikan L-3).
     *
     * Audit pra-deploy mendapati tidak ada satu pun jalur aplikasi yang
     * menghapus atau mengubah tabel ini: tidak ada rute, controller, service,
     * maupun perintah konsol. Sifat itu benar hari ini karena tidak ada yang
     * pernah menulisnya, bukan karena ada yang mencegahnya — dan jejak audit
     * yang bergantung pada ketiadaan kode adalah jejak yang akan hilang pada
     * sesi ke sekian, ketika seseorang menambahkan satu pembersih data yang
     * tampak tidak berbahaya.
     *
     * Pelanggarannya melempar, bukan diam. Baris audit yang gagal dihapus
     * harus terdengar keras; yang berbahaya justru penghapusan yang berhasil
     * tanpa ada yang tahu.
     *
     * Ini pagar LAPIS APLIKASI. Ia tidak menghentikan siapa pun yang sudah
     * memegang akses SQL langsung ke basis data — untuk itu cabut hak DELETE
     * dan UPDATE atas tabel ini dari pengguna MySQL aplikasi, atau kirimkan
     * salinannya ke penyimpanan terpisah. Lihat docs/06-KEAMANAN-Deploy.md.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException(
                'log_aktivitas bersifat append-only: baris audit tidak boleh diubah.',
            );
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'log_aktivitas bersifat append-only: baris audit tidak boleh dihapus.',
            );
        });
    }

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
