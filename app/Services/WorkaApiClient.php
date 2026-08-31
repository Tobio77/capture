<?php

namespace App\Services;

use App\Exceptions\WorkaApiException;
use App\Support\PengaturanRepository;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Klien API integrasi WORKA (prefix /api/v1/absen).
 *
 * Seluruh endpoint WORKA bersifat baca-saja dan membungkus jawabannya dalam
 * amplop {success, data, meta?}; kegagalan berbentuk {success, message, code}.
 * Kelas ini membuka amplop itu sehingga pemanggil bekerja dengan array biasa.
 *
 * Kredensial dibaca dari tabel `pengaturan` lebih dulu, baru jatuh ke
 * config/services.php — admin dapat mengganti token tanpa menyunting .env.
 */
class WorkaApiClient
{
    public const string KUNCI_URL = 'worka.api_url';

    public const string KUNCI_TOKEN = 'worka.api_token';

    protected string $baseUrl;

    protected ?string $token;

    protected int $timeout;

    public function __construct(protected PengaturanRepository $pengaturan)
    {
        $this->baseUrl = rtrim(
            $pengaturan->ambil(self::KUNCI_URL) ?: (string) config('services.worka.api_url'),
            '/',
        );
        $this->token = $pengaturan->ambil(self::KUNCI_TOKEN) ?: config('services.worka.api_token');
        $this->timeout = (int) config('services.worka.timeout', 30);
    }

    public function terkonfigurasi(): bool
    {
        return filled($this->token) && filled($this->baseUrl);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Uji koneksi tanpa melempar exception.
     */
    public function cekKoneksi(): bool
    {
        try {
            $this->getSyncStatus();

            return true;
        } catch (WorkaApiException) {
            return false;
        }
    }

    /**
     * Status sinkronisasi beserta checksum seluruh pegawai aktif.
     *
     * @return array{total_pegawai_aktif: int, terakhir_diupdate: ?string, checksum: string, server_time: string}
     */
    public function getSyncStatus(): array
    {
        $endpoint = '/api/v1/absen/sync-status';

        return $this->bukaAmplop(
            $this->panggil($endpoint, true, fn (PendingRequest $r) => $r->get($endpoint)),
            $endpoint,
        )['data'];
    }

    /**
     * Daftar pegawai terpaginasi.
     *
     * @param  array<string, mixed>  $params  unit_kerja_kode, updated_after, per_page, page, status_aktif
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function getPegawai(array $params = []): array
    {
        $endpoint = '/api/v1/absen/pegawai';

        $isi = $this->bukaAmplop(
            $this->panggil($endpoint, true, fn (PendingRequest $r) => $r->get($endpoint, $params)),
            $endpoint,
        );

        return ['data' => $isi['data'] ?? [], 'meta' => $isi['meta'] ?? []];
    }

    /**
     * Detail satu pegawai. Mengembalikan null bila NIP tidak terdaftar.
     *
     * @return array<string, mixed>|null
     */
    public function getPegawaiByNip(string $nip): ?array
    {
        $endpoint = "/api/v1/absen/pegawai/{$nip}";

        try {
            return $this->bukaAmplop(
                $this->panggil($endpoint, true, fn (PendingRequest $r) => $r->get($endpoint)),
                $endpoint,
            )['data'];
        } catch (WorkaApiException $e) {
            if ($e->httpStatus === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Validasi NIP. Sengaja tanpa percobaan ulang — dipakai pada jalur cepat.
     *
     * Jawaban WORKA diteruskan apa adanya, termasuk bentuk gagalnya
     * ({success:false, code, message}), karena kiosk perlu membedakan
     * NIP_NOT_FOUND dari PEGAWAI_TIDAK_AKTIF.
     *
     * @return array{success: bool, data?: array<string, mixed>, code?: string, message?: string}
     */
    public function validasiNip(string $nip): array
    {
        $endpoint = '/api/v1/absen/validasi-nip';

        $respons = $this->panggil(
            $endpoint,
            false,
            fn (PendingRequest $r) => $r->post($endpoint, ['nip' => $nip]),
        );

        $isi = $respons->json();

        if (is_array($isi) && array_key_exists('success', $isi)) {
            return $isi;
        }

        throw new WorkaApiException(
            'Jawaban WORKA tidak dikenali saat memvalidasi NIP.',
            httpStatus: $respons->status(),
            workaCode: 'JAWABAN_TIDAK_DIKENALI',
            endpoint: $endpoint,
        );
    }

    /**
     * URL lengkap endpoint foto pegawai di WORKA.
     */
    public function getFotoUrl(string $nip): string
    {
        return "{$this->baseUrl}/api/v1/absen/pegawai/{$nip}/foto";
    }

    /**
     * Ambil berkas foto pegawai apa adanya untuk di-proxy ke kiosk,
     * sehingga token WORKA tidak pernah sampai ke browser kiosk.
     */
    public function ambilFoto(string $nip): Response
    {
        $endpoint = "/api/v1/absen/pegawai/{$nip}/foto";

        return $this->panggil($endpoint, false, fn (PendingRequest $r) => $r->get($endpoint));
    }

    /**
     * Daftar unit kerja.
     *
     * @param  array<string, mixed>  $params  is_blk, jenis_unit, aktif
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function getUnitKerja(array $params = []): array
    {
        $endpoint = '/api/v1/absen/unit-kerja';

        $isi = $this->bukaAmplop(
            $this->panggil($endpoint, true, fn (PendingRequest $r) => $r->get($endpoint, $params)),
            $endpoint,
        );

        return ['data' => $isi['data'] ?? [], 'meta' => $isi['meta'] ?? []];
    }

    /**
     * Jalankan satu permintaan, ubah kegagalan koneksi menjadi WorkaApiException.
     *
     * @param  Closure(PendingRequest): Response  $kirim
     *
     * @throws WorkaApiException
     */
    protected function panggil(string $endpoint, bool $ulangi, Closure $kirim): Response
    {
        if (! $this->terkonfigurasi()) {
            throw WorkaApiException::tanpaToken();
        }

        try {
            return $kirim($this->permintaan($ulangi));
        } catch (ConnectionException $e) {
            throw WorkaApiException::dariKoneksi($endpoint, $e);
        }
    }

    protected function permintaan(bool $ulangi): PendingRequest
    {
        $permintaan = Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->timeout($this->timeout)
            ->acceptJson();

        /*
         * Endpoint sinkronisasi dibatasi 10 permintaan/menit di WORKA, jadi
         * percobaan ulang hanya dilakukan untuk kegagalan koneksi. Token yang
         * salah atau ability yang kurang tidak akan membaik bila diulang, dan
         * mengulangnya justru menghabiskan jatah laju.
         */
        return $ulangi
            ? $permintaan->retry(
                times: 3,
                sleepMilliseconds: 1000,
                when: fn (?\Throwable $galat) => $galat instanceof ConnectionException,
                throw: false,
            )
            : $permintaan;
    }

    /**
     * Buka amplop {success, data, meta} dan ubah kegagalan menjadi exception.
     *
     * @return array<string, mixed>
     *
     * @throws WorkaApiException
     */
    protected function bukaAmplop(Response $respons, string $endpoint): array
    {
        $isi = $respons->json();

        if ($respons->failed()) {
            throw new WorkaApiException(
                "Permintaan ke WORKA gagal ({$respons->status()}) pada {$endpoint}.",
                httpStatus: $respons->status(),
                workaCode: is_array($isi) ? ($isi['code'] ?? null) : null,
                workaMessage: is_array($isi) ? ($isi['message'] ?? null) : null,
                endpoint: $endpoint,
            );
        }

        if (! is_array($isi) || ($isi['success'] ?? false) !== true) {
            throw new WorkaApiException(
                "Jawaban WORKA tidak dikenali pada {$endpoint}.",
                httpStatus: $respons->status(),
                workaCode: is_array($isi) ? ($isi['code'] ?? 'JAWABAN_TIDAK_DIKENALI') : 'JAWABAN_TIDAK_DIKENALI',
                workaMessage: is_array($isi) ? ($isi['message'] ?? null) : null,
                endpoint: $endpoint,
            );
        }

        return $isi;
    }
}
