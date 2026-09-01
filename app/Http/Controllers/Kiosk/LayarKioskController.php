<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Services\EventAbsenService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LayarKioskController extends Controller
{
    public function __construct(protected EventAbsenService $event) {}

    /**
     * Layar utama kiosk. Dua panel Capture Foto & Daftar e-Presensi
     * dibangun pada Sesi S13.
     */
    public function __invoke(Request $request): Response
    {
        $kiosk = $request->kiosk();
        $event = $kiosk === null ? null : $this->event->eventAktifUntukKiosk($kiosk);

        /*
         * FR-EVT-03: kiosk yang membuka layar untuk sebuah event sudah
         * terhitung terhubung, tidak perlu menunggu tap pertama.
         */
        if ($event !== null && $kiosk !== null) {
            $this->event->catatKioskAktif($event, $kiosk, $request->ip());
        }

        // Prop `kiosk` sudah dibagikan HandleInertiaRequests; jangan ditimpa
        // di sini agar bentuknya tetap sama di seluruh layar kiosk.
        return Inertia::render('Kiosk/Utama', [
            // Null berarti tidak ada entry yang dibuka untuk unit ini, dan
            // layar kiosk menampilkan keadaan itu alih-alih menerima tap.
            'event' => $event === null ? null : [
                'id' => $event->id,
                'nama' => $event->nama,
                'tanggal' => $event->tanggal->toDateString(),
                'jam_mulai' => substr((string) $event->jam_mulai, 0, 5),
                'toleransi_menit' => $event->toleransi_menit,
            ],
        ]);
    }
}
