<?php

namespace App\Jobs;

use App\Enums\StatusRiwayatLaporan;
use App\Exports\LaporanResmiExport;
use App\Models\RiwayatLaporan;
use App\Services\Laporan\LaporanResmiService;
use App\Services\Laporan\LaporanWordService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Throwable;

/**
 * Merakit satu berkas Laporan Resmi (FR-LAP-04) di belakang layar.
 *
 * Dikirim dengan `dispatch(...)->afterResponse()` (lihat
 * LaporanController::generate()), BUKAN lewat worker antrian biasa
 * (`php artisan queue:work`) yang harus terus dijalankan terpisah — di
 * lingkungan pengembangan/produksi yang tidak selalu menjaga proses worker
 * itu tetap hidup, laporan akan tersangkut "antre" selamanya, persis
 * keluhan yang mendorong fitur ini dibuat. `afterResponse()` menjalankan
 * job ini pada proses PHP yang sama setelah jawaban HTTP-nya terkirim ke
 * peramban — permintaan tetap terasa cepat, tetapi tidak butuh proses
 * latar belakang terpisah yang gampang lupa dinyalakan.
 *
 * Tetap `ShouldQueue` (bukan dipanggil langsung) supaya PERILAKUNYA sama
 * persis andai suatu saat dipindah ke worker sungguhan — hanya cara
 * pengirimannya yang berbeda.
 */
class BuatLaporanResmiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(protected int $riwayatId) {}

    public function handle(
        LaporanResmiService $laporanResmi,
        LaporanWordService $laporanWord,
    ): void {
        $riwayat = RiwayatLaporan::query()->find($this->riwayatId);

        // Sudah dihapus admin sebelum sempat diproses — tidak ada lagi yang
        // perlu dikerjakan, dan tidak ada baris untuk ditandai gagal.
        if ($riwayat === null) {
            return;
        }

        $riwayat->update(['status' => StatusRiwayatLaporan::Diproses]);

        try {
            $data = $laporanResmi->susun(
                $riwayat->user,
                $riwayat->dari,
                $riwayat->sampai,
                $riwayat->unit_kerja_id,
            );

            $isi = match ($riwayat->format) {
                'docx' => $laporanWord->bytes($data + $this->jejakCetak($riwayat)),
                'xlsx' => (new LaporanResmiExport($data))->raw(ExcelWriter::XLSX),
                default => $this->renderPdf($data),
            };

            $path = "laporan-resmi/{$riwayat->id}-{$riwayat->nama_berkas}";
            Storage::disk('local')->put($path, $isi);

            $riwayat->update([
                'status' => StatusRiwayatLaporan::Selesai,
                'path' => $path,
                'selesai_pada' => now(),
            ]);
        } catch (Throwable $e) {
            // Pesan lengkapnya ke log untuk ditelusuri, bukan ke admin —
            // jejak tumpukan panggilan dan detail internal tidak ada
            // gunanya bagi yang membaca Riwayat, dan berpotensi membocorkan
            // struktur aplikasi.
            Log::error('Generate Laporan Resmi gagal', [
                'riwayat_id' => $riwayat->id,
                'exception' => $e,
            ]);

            $riwayat->update([
                'status' => StatusRiwayatLaporan::Gagal,
                'pesan_galat' => 'Laporan gagal dibuat. Coba lagi, atau hubungi admin bila terus terjadi.',
            ]);
        }
    }

    protected function renderPdf(array $data): string
    {
        return Pdf::loadView('cetak.laporan-resmi', [
            'data' => $data,
            'formatPersen' => fn (?float $n) => LaporanResmiService::formatPersen($n),
            'dicetak' => now()->translatedFormat('d F Y H:i'),
            'oleh' => $data['oleh'] ?? 'sistem',
        ])
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * @return array<string, string>
     */
    protected function jejakCetak(RiwayatLaporan $riwayat): array
    {
        return [
            'dicetak' => now()->translatedFormat('d F Y H:i'),
            'oleh' => $riwayat->user->nama ?? 'sistem',
        ];
    }
}
