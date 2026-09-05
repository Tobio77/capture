<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AksiLog;
use App\Exceptions\WorkaApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanSettingWorkaRequest;
use App\Services\LogAktivitasService;
use App\Services\SinkronisasiPegawaiService;
use App\Services\WorkaApiClient;
use App\Support\PengaturanRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setting → Integrasi WORKA.
 *
 * Token disimpan terenkripsi di tabel `pengaturan`, bukan di .env, sehingga
 * admin dapat memperbaruinya tanpa akses ke server.
 */
class SettingWorkaController extends Controller
{
    public function __construct(
        protected PengaturanRepository $pengaturan,
        protected LogAktivitasService $log,
    ) {}

    public function edit(SinkronisasiPegawaiService $sinkronisasi): Response
    {
        return Inertia::render('Setting/Worka', [
            'api_url' => $this->pengaturan->ambil(WorkaApiClient::KUNCI_URL)
                ?: (string) config('services.worka.api_url'),

            // Token tidak pernah dikirim ke browser — hanya keterangan
            // apakah sudah terisi.
            'token_terisi' => filled(
                $this->pengaturan->ambil(WorkaApiClient::KUNCI_TOKEN)
                ?: config('services.worka.api_token'),
            ),
            'token_dari_env' => blank($this->pengaturan->ambil(WorkaApiClient::KUNCI_TOKEN))
                && filled(config('services.worka.api_token')),
            'status_sinkron' => $sinkronisasi->statusSinkron(cekKoneksi: false),
        ]);
    }

    public function update(SimpanSettingWorkaRequest $request): RedirectResponse
    {
        $this->pengaturan->simpan(
            WorkaApiClient::KUNCI_URL,
            rtrim($request->string('api_url')->toString(), '/'),
        );

        // Kolom token yang dibiarkan kosong berarti "jangan ubah",
        // bukan "hapus token" — kolomnya memang tampil kosong saat dimuat.
        if ($request->filled('api_token')) {
            $this->pengaturan->simpan(
                WorkaApiClient::KUNCI_TOKEN,
                $request->string('api_token')->toString(),
                terenkripsi: true,
            );
        }

        $this->log->catat(
            AksiLog::Ubah,
            'Mengubah pengaturan integrasi WORKA'
                .($request->filled('api_token') ? ' beserta token API.' : '.'),
            user: $request->user(),
        );

        return back()->with('sukses', 'Pengaturan integrasi WORKA berhasil disimpan.');
    }

    /**
     * Uji koneksi ke WORKA memakai kredensial yang tersimpan.
     */
    public function uji(Request $request): JsonResponse
    {
        // Klien dibangun ulang agar memakai nilai yang baru saja disimpan.
        $this->pengaturan->bersihkanIngatan();
        $klien = app(WorkaApiClient::class);

        if (! $klien->terkonfigurasi()) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Token API WORKA belum diisi.',
            ]);
        }

        $mulai = microtime(true);

        try {
            $status = $klien->getSyncStatus();

            return response()->json([
                'sukses' => true,
                'pesan' => 'Terhubung ke WORKA.',
                'durasi_ms' => (int) round((microtime(true) - $mulai) * 1000),
                'total_pegawai_aktif' => $status['total_pegawai_aktif'] ?? 0,
                'server_time' => $status['server_time'] ?? null,
            ]);
        } catch (WorkaApiException $e) {
            return response()->json([
                'sukses' => false,
                'pesan' => $e->pesanUntukAdmin(),
                'kode' => $e->workaCode,
                'http_status' => $e->httpStatus,
            ]);
        }
    }
}
