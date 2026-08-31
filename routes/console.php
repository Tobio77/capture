<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Sinkronisasi harian data pegawai dari WORKA (FR-PEG-01).
 *
 * Dijadwalkan pukul 02.00 — di luar jam kegiatan, sehingga tarikan ratusan
 * baris tidak berebut dengan kiosk yang sedang melayani tap. Bila checksum
 * WORKA belum berubah, perintah ini berhenti tanpa menarik data apa pun.
 */
Schedule::command('pegawai:sinkron')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/sinkron-pegawai.log'));
