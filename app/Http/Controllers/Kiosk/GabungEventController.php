<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Services\KodeUnitEventService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Penggabungan perangkat absen ke sebuah event lewat kode unit kerja
 * (FR-EVT-03, revisi S29).
 *
 * Kode ini BUKAN kode aktivasi perangkat. Kode aktivasi menentukan boleh
 * tidaknya sebuah mesin menjadi titik absen sama sekali dan ditukar sekali
 * dengan device_token; kode unit kerja menentukan event mana yang dilayani
 * titik absen itu, boleh dipakai berkali-kali oleh beberapa perangkat, dan
 * habis masa gunanya begitu eventnya ditutup.
 *
 * Karena itu Mode Terbuka (FR-SET-06) tidak berlaku di sini: ia melonggarkan
 * kode aktivasi, bukan kode unit kerja. Perangkat ad-hoc yang masuk lewat Mode
 * Terbuka tetap harus mengetikkan kode untuk melayani sebuah kegiatan.
 */
class GabungEventController extends Controller
{
    public function __construct(protected KodeUnitEventService $kode) {}

    /**
     * Tukarkan kode unit kerja dengan keanggotaan pada eventnya.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            ['kode' => ['required', 'string', 'max:32']],
            ['kode.required' => 'Kode unit kerja wajib diisi.'],
        );

        $event = $this->kode->gabungkan($data['kode'], $request->kiosk(), $request);

        if ($event === null) {
            /*
             * Kode salah dan event sudah ditutup dijawab pesan yang sama.
             * Membedakannya akan mengubah kolom ini menjadi alat menebak: kode
             * acak yang "salah kode" versus "event ditutup" memberi tahu
             * penebak bahwa ia sudah menemukan kode yang benar.
             */
            throw ValidationException::withMessages([
                'kode' => 'Kode unit kerja tidak dikenal, atau eventnya sudah ditutup. Mintakan kode terbaru kepada admin penyelenggara.',
            ]);
        }

        return redirect()
            ->route('kiosk.event.layar')
            ->with('sukses', "Perangkat bergabung ke event {$event->nama}.");
    }

    /**
     * Keluar dari event yang sedang dilayani.
     *
     * Diperlukan setiap kali satu perangkat melayani lebih dari satu kegiatan
     * berturut-turut, dan setiap kali perangkat dipindahkan ke ruangan lain —
     * tanpa ini, satu-satunya cara melepaskannya adalah mencabut device token,
     * yang berarti mengaktivasi ulang perangkatnya.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $kiosk = $request->kiosk();
        $event = $this->kode->eventYangDiikuti($kiosk);

        if ($event === null) {
            return redirect()->route('beranda');
        }

        $this->kode->keluarkan($event, $kiosk);

        return redirect()
            ->route('beranda')
            ->with('sukses', "Perangkat keluar dari event {$event->nama}.");
    }
}
