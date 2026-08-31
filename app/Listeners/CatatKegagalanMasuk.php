<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\LogAktivitasService;
use Illuminate\Auth\Events\Failed;

class CatatKegagalanMasuk
{
    public function __construct(protected LogAktivitasService $log) {}

    public function handle(Failed $event): void
    {
        // $event->credentials memuat kata sandi — hanya alamat surel yang diambil.
        $email = $event->credentials['email'] ?? null;

        $this->log->catatMasukGagal(
            is_string($email) ? $email : null,
            $event->user instanceof User ? $event->user : null,
        );
    }
}
