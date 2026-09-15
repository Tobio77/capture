<?php

namespace App\Console\Commands;

use App\Enums\StatusRiwayatLaporan;
use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupAbsensiCommand extends Command
{
    protected $signature = 'absensi:backup';

    protected $description = 'Backup data absensi terjadwal dan bersihkan backup yang melampaui retensi (FR-MTN-01)';

    public function handle(BackupService $backup): int
    {
        $this->info('Memulai backup data absensi…');

        $riwayat = $backup->buatTerjadwal();

        if ($riwayat->status !== StatusRiwayatLaporan::Selesai) {
            $this->error('Backup gagal: '.$riwayat->pesan_galat);
            $this->line('  Rincian galat tercatat di storage/logs/laravel.log');

            return self::FAILURE;
        }

        $this->line("  <fg=green>✓</> Berkas: {$riwayat->nama_berkas} ({$riwayat->ukuran_bytes} bytes)");

        $dihapus = $backup->bersihkanKedaluwarsa();

        if ($dihapus > 0) {
            $this->line("  <fg=green>✓</> Backup lama dihapus: {$dihapus} (melampaui retensi {$backup->retensiHari()} hari)");
        }

        $this->newLine();
        $this->info('Backup selesai.');

        return self::SUCCESS;
    }
}
