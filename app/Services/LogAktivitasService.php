<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Models\Kiosk;
use App\Models\LogAktivitas;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Penulisan audit trail (FR-AUTH-03).
 *
 * Konteks permintaan (IP dan user agent) diambil dari request aktif bila ada,
 * sehingga pemanggil cukup menyebut siapa dan aksi apa.
 */
class LogAktivitasService
{
    public function __construct(protected ?Request $request = null) {}

    public function catat(
        AksiLog $aksi,
        string $deskripsi,
        ?User $user = null,
        ?Kiosk $kiosk = null,
        ?Model $subjek = null,
    ): LogAktivitas {
        return LogAktivitas::create([
            'user_id' => $user?->id,
            'kiosk_id' => $kiosk?->id,
            'aksi' => $aksi,
            'deskripsi' => Str::limit($deskripsi, 255, ''),
            'subjek_type' => $subjek ? $subjek::class : null,
            'subjek_id' => $subjek?->getKey(),
            'ip_address' => $this->request?->ip(),
            'user_agent' => Str::limit((string) $this->request?->userAgent(), 255, ''),
        ]);
    }

    public function catatMasuk(User $user): LogAktivitas
    {
        return $this->catat(
            AksiLog::Masuk,
            "{$user->nama} ({$user->role->label()}) masuk ke Panel Admin.",
            user: $user,
        );
    }

    public function catatKeluar(User $user): LogAktivitas
    {
        return $this->catat(
            AksiLog::Keluar,
            "{$user->nama} keluar dari Panel Admin.",
            user: $user,
        );
    }

    /**
     * Percobaan masuk yang gagal dicatat tanpa pelaku, karena identitasnya belum terbukti.
     * Kata sandi tidak pernah ikut dicatat.
     */
    public function catatMasukGagal(?string $email, ?User $user = null): LogAktivitas
    {
        $identitas = $email !== null && $email !== '' ? $email : 'tanpa alamat surel';

        return $this->catat(
            AksiLog::MasukGagal,
            "Percobaan masuk gagal untuk {$identitas}.",
            user: $user,
        );
    }
}
