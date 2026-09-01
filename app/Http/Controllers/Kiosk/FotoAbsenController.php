<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Services\AbsensiService;
use App\Services\EventAbsenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sajikan foto absen ke layar kiosk (NFR-04).
 *
 * Foto tersimpan pada disk privat dan hanya dapat diambil oleh kiosk yang
 * sedang melayani event yang sama — perangkat lain tidak berkepentingan atas
 * foto kehadiran unit ini.
 */
class FotoAbsenController extends Controller
{
    public function __construct(protected EventAbsenService $event) {}

    public function __invoke(Request $request, Absensi $absensi): StreamedResponse
    {
        $kiosk = $request->kiosk();
        $event = $kiosk === null ? null : $this->event->eventAktifUntukKiosk($kiosk);

        abort_unless($event !== null && $event->id === $absensi->event_absen_id, 403);
        abort_if($absensi->foto_path === null, 404);

        $disk = Storage::disk(AbsensiService::DISK);

        abort_unless($disk->exists($absensi->foto_path), 404);

        return $disk->response(
            $absensi->foto_path,
            headers: ['Cache-Control' => 'private, max-age=300'],
        );
    }
}
