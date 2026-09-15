<?php

namespace App\Jobs;

use App\Models\RiwayatBackup;
use App\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Rakit satu arsip backup data absensi di belakang layar (FR-MTN-01).
 *
 * Dikirim dengan `dispatch(...)->afterResponse()` (lihat
 * BackupService::buatManual()), BUKAN lewat worker antrian biasa — alasan
 * yang sama persis dengan {@see BuatLaporanResmiJob}: di lingkungan yang
 * tidak selalu menjaga proses `queue:work` tetap hidup, backup akan
 * tersangkut "antre" selamanya. `afterResponse()` menjalankan job ini pada
 * proses PHP yang sama setelah jawaban HTTP-nya terkirim ke peramban.
 *
 * Tetap `ShouldQueue` supaya PERILAKUNYA sama persis andai suatu saat
 * dipindah ke worker sungguhan — hanya cara pengirimannya yang berbeda.
 */
class BuatBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(protected int $riwayatId) {}

    public function handle(BackupService $backup): void
    {
        $riwayat = RiwayatBackup::query()->find($this->riwayatId);

        // Sudah dihapus admin sebelum sempat diproses — tidak ada lagi yang
        // perlu dikerjakan.
        if ($riwayat === null) {
            return;
        }

        $backup->jalankanSinkron($riwayat);
    }
}
