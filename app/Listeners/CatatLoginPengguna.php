<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\LogAktivitasService;
use Illuminate\Auth\Events\Login;

class CatatLoginPengguna
{
    public function __construct(protected LogAktivitasService $log) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->log->catatMasuk($event->user);
    }
}
