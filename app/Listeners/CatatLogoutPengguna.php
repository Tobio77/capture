<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\LogAktivitasService;
use Illuminate\Auth\Events\Logout;

class CatatLogoutPengguna
{
    public function __construct(protected LogAktivitasService $log) {}

    public function handle(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->log->catatKeluar($event->user);
    }
}
