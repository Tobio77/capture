<?php

namespace App\Console\Commands;

use App\Services\SinkronisasiPegawaiService;
use App\Support\SinkronResult;
use Illuminate\Console\Command;

class SinkronPegawaiCommand extends Command
{
    protected $signature = 'pegawai:sinkron {--penuh : Tarik ulang seluruh pegawai, bukan hanya yang berubah}';

    protected $description = 'Sinkronkan data unit kerja dan pegawai dari WORKA (FR-PEG-01)';

    public function handle(SinkronisasiPegawaiService $sinkronisasi): int
    {
        $penuh = (bool) $this->option('penuh');

        $this->info('Memulai sinkronisasi data pegawai dari WORKA'.($penuh ? ' (penuh)' : '').'…');

        $hasil = $penuh ? $sinkronisasi->sinkronPenuh() : $sinkronisasi->sinkronInkremental();

        return match ($hasil->status) {
            SinkronResult::TIDAK_ADA_PERUBAHAN => $this->laporkanTanpaPerubahan($hasil),
            SinkronResult::SUKSES => $this->laporkanSukses($hasil),
            default => $this->laporkanGagal($hasil),
        };
    }

    protected function laporkanSukses(SinkronResult $hasil): int
    {
        $this->line('  <fg=green>✓</> Unit kerja: '.$hasil->unitKerjaDiperbarui.' diperbarui');
        $this->line('  <fg=green>✓</> Pegawai ditambahkan: '.$hasil->ditambahkan);
        $this->line('  <fg=green>✓</> Pegawai diperbarui: '.$hasil->diperbarui);
        $this->line('  <fg=green>✓</> Pegawai dinonaktifkan: '.$hasil->dinonaktifkan);
        $this->line('  <fg=green>✓</> Durasi: '.number_format($hasil->durasiDetik, 1).' detik');
        $this->newLine();
        $this->info('Sinkronisasi selesai.');

        return self::SUCCESS;
    }

    protected function laporkanTanpaPerubahan(SinkronResult $hasil): int
    {
        $this->line('  <fg=gray>–</> '.$hasil->pesan);
        $this->newLine();
        $this->info('Sinkronisasi selesai tanpa perubahan.');

        return self::SUCCESS;
    }

    protected function laporkanGagal(SinkronResult $hasil): int
    {
        $this->newLine();
        $this->error('Sinkronisasi gagal: '.$hasil->error);
        $this->line('  Rincian galat tercatat di storage/logs/worka-api.log');

        return self::FAILURE;
    }
}
