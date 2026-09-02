<?php

namespace App\Http\Controllers\Absen;

use App\Exceptions\WorkaApiException;
use App\Http\Controllers\Controller;
use App\Models\Pegawai;
use App\Services\EventAbsenService;
use App\Services\TitikAbsenService;
use App\Services\WorkaApiClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proxy foto referensi wajah dari WORKA ke kiosk (NFR-04).
 *
 * Kiosk tidak pernah memanggil WORKA langsung: token API WORKA memiliki
 * ability absen:read-foto atas SELURUH pegawai, dan menaruhnya di browser
 * kiosk berarti menyerahkan seluruh basis wajah kepada siapa pun yang
 * membuka devtools di titik absen. Rute ini dipagari device_token kiosk.
 */
class FotoPegawaiController extends Controller
{
    public function __construct(
        protected WorkaApiClient $worka,
        protected EventAbsenService $event,
        protected TitikAbsenService $titik,
    ) {}

    public function __invoke(Request $request, string $nip): Response
    {
        // NIP harus dikenal SI-ABSEN lebih dulu; rute ini tidak boleh menjadi
        // jalan memindai seluruh NIP yang ada di WORKA.
        $pegawai = Pegawai::query()->where('nip', $nip)->first();

        if ($pegawai === null || ! $pegawai->aktif) {
            abort(404, 'Foto pegawai tidak tersedia.');
        }

        /*
         * Perangkat hanya boleh melihat foto pegawai yang memang dapat absen
         * padanya. Tanpa pembatasan ini, satu perangkat yang dikuasai orang
         * lain dapat memanen foto seluruh pegawai dinas hanya dengan
         * menelusuri NIP — padahal ia cuma perlu menampilkan wajah orang yang
         * baru saja men-tap di depannya.
         */
        ['event' => $eventAktif] = $this->titik->untuk($request);

        if ($eventAktif === null
            || ! in_array($pegawai->unit_kerja_id, $this->event->unitTercakup($eventAktif), true)
        ) {
            abort(404, 'Foto pegawai tidak tersedia.');
        }

        try {
            $jawaban = $this->worka->ambilFoto($nip);
        } catch (WorkaApiException) {
            abort(502, 'Foto pegawai tidak dapat diambil dari WORKA.');
        }

        if ($jawaban->failed()) {
            abort($jawaban->status() === 404 ? 404 : 502, 'Foto pegawai tidak tersedia.');
        }

        return response($jawaban->body(), 200, [
            'Content-Type' => $jawaban->header('Content-Type') ?: 'image/jpeg',

            // "private": foto wajah tidak boleh disimpan proxy bersama.
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
