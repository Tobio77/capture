<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RiwayatBackup;
use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Maintenance & Backup — arsip data absensi (FR-MTN-01). Superadmin saja
 * (otorisasi lewat middleware `peran:superadmin` pada route).
 *
 * Sama seperti Riwayat Laporan: backup TIDAK langsung dibuat pada
 * permintaan yang sama — `buat()` mengantrekannya lewat
 * `BackupService::buatManual()`, dan berkasnya diunduh belakangan dari
 * daftar riwayat di halaman ini.
 */
class MaintenanceController extends Controller
{
    public function __construct(protected BackupService $backup) {}

    public function edit(Request $request): Response
    {
        return Inertia::render('Setting/Maintenance', [
            'riwayat' => $this->backup->untukLayar(),
            'retensi_hari' => $this->backup->retensiHari(),
            'batas' => [
                'retensi_min' => BackupService::RETENSI_MIN_HARI,
                'retensi_maks' => BackupService::RETENSI_MAKS_HARI,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'retensi_hari' => [
                'required', 'integer',
                'min:'.BackupService::RETENSI_MIN_HARI,
                'max:'.BackupService::RETENSI_MAKS_HARI,
            ],
        ]);

        $this->backup->simpanRetensi($data['retensi_hari']);

        return back()->with('sukses', 'Retensi backup disimpan.');
    }

    /**
     * Antrekan backup manual — pola sama dengan LaporanController::generate().
     */
    public function buat(Request $request): RedirectResponse
    {
        $this->backup->buatManual($request->user());

        return back()->with('sukses', 'Backup sedang diproses. Lihat progresnya di Riwayat Backup di bawah.');
    }

    /**
     * Dipanggil berkala dari halaman selama ada baris yang masih
     * antre/diproses.
     */
    public function data(Request $request): JsonResponse
    {
        return response()->json(['riwayat' => $this->backup->untukLayar()]);
    }

    public function unduh(Request $request, RiwayatBackup $riwayatBackup): StreamedResponse
    {
        abort_unless($riwayatBackup->selesai() && $riwayatBackup->path !== null, 404);
        abort_unless(Storage::disk(BackupService::DISK)->exists($riwayatBackup->path), 404);

        return Storage::disk(BackupService::DISK)->download($riwayatBackup->path, $riwayatBackup->nama_berkas);
    }

    public function hapus(Request $request, RiwayatBackup $riwayatBackup): RedirectResponse
    {
        $this->backup->hapus($riwayatBackup);

        return back()->with('sukses', 'Riwayat backup dihapus.');
    }

    /**
     * Pulihkan (restore) data dari backup ini — MENGGABUNGKAN, bukan
     * mengganti total (lihat docblock BackupService::pulihkan()).
     *
     * `konfirmasi` wajib PERSIS SAMA dengan nama berkas backup — diperiksa
     * di server, bukan hanya di formulir: aksi ini menulis ulang puluhan
     * hingga ratusan baris lintas beberapa tabel sekaligus, dan tombol yang
     * bisa tertekan tanpa sengaja tidak boleh cukup untuk memicunya.
     */
    public function pulihkan(Request $request, RiwayatBackup $riwayatBackup): RedirectResponse
    {
        abort_unless($riwayatBackup->selesai() && $riwayatBackup->path !== null, 404);
        abort_unless(Storage::disk(BackupService::DISK)->exists($riwayatBackup->path), 404);

        $data = $request->validate(['konfirmasi' => ['required', 'string']]);

        if ($data['konfirmasi'] !== $riwayatBackup->nama_berkas) {
            return back()->withErrors(['konfirmasi' => 'Nama berkas yang diketik tidak cocok.']);
        }

        try {
            $ringkasan = $this->backup->pulihkan($riwayatBackup, $request->user());
        } catch (Throwable $e) {
            report($e);

            return back()->with('gagal', 'Pemulihan gagal: berkas backup tidak dapat dibaca atau rusak.');
        }

        $rincian = collect($ringkasan)->map(fn ($jumlah, $nama) => "{$nama}: {$jumlah}")->implode(', ');

        return back()->with('sukses', "Data berhasil dipulihkan ({$rincian}).");
    }
}
