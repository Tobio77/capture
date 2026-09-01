<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CakupanEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanEventRequest;
use App\Models\EventAbsen;
use App\Models\UnitKerja;
use App\Services\EventAbsenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daftar dan pengelolaan event absensi (FR-EVT-01, FR-EVT-02).
 *
 * Menutup event (FR-EVT-04) dan detail kiosk terhubung (FR-EVT-03, FR-EVT-05)
 * dikerjakan pada S11 dan S12.
 */
class EventController extends Controller
{
    public function __construct(protected EventAbsenService $event) {}

    public function index(Request $request): Response
    {
        $pengguna = $request->user();

        return Inertia::render('Event/Index', [
            'daftar' => $this->event->daftar($pengguna),
            'unit_kerja' => $this->event->unitKerjaTersedia($pengguna),
            'nilai_awal' => $this->event->nilaiAwal(),

            // Cakupan "semua unit" hanya untuk peran lintas unit (FR-EVT-01).
            'boleh_semua_unit' => $pengguna->lintasUnit(),
            'cakupan_semua_unit' => CakupanEvent::SemuaUnit->value,
        ]);
    }

    public function store(SimpanEventRequest $request): RedirectResponse
    {
        $event = $this->event->buat($request->validated(), $request->user());

        return back()->with('sukses', "Event {$event->nama} berhasil dibuat.");
    }

    public function update(SimpanEventRequest $request, EventAbsen $event): RedirectResponse
    {
        abort_unless($this->boleh($request, $event), 403);

        // Event yang sudah ditutup adalah catatan riwayat; perubahannya akan
        // menggeser makna absensi yang terlanjur tercatat di bawahnya.
        abort_unless($event->aktif(), 403, 'Event yang sudah ditutup tidak dapat diubah.');

        $this->event->perbarui($event, $request->validated(), $request->user());

        return back()->with('sukses', "Event {$event->nama} berhasil diperbarui.");
    }

    /**
     * Tutup entry event (FR-EVT-04).
     */
    public function tutup(Request $request, EventAbsen $event): RedirectResponse
    {
        abort_unless($this->boleh($request, $event), 403);
        abort_unless($event->aktif(), 403, 'Event ini sudah ditutup.');

        $this->event->tutup($event, $request->user());

        return back()->with(
            'sukses',
            "Entry event {$event->nama} ditutup. Tap baru pada kiosk akan ditolak.",
        );
    }

    /**
     * Hapus event secara permanen.
     *
     * Hanya diizinkan selama event belum menautkan satu pun absensi; statusnya
     * sendiri tidak menentukan, sehingga event salah-buat yang sudah terlanjur
     * ditutup pun masih dapat dibersihkan.
     */
    public function destroy(Request $request, EventAbsen $event): RedirectResponse
    {
        abort_unless($this->boleh($request, $event), 403);

        abort_unless(
            $this->event->dapatDihapus($event),
            403,
            'Event yang sudah memiliki absensi tidak dapat dihapus.',
        );

        $nama = $event->nama;

        $this->event->hapus($event, $request->user());

        return back()->with('sukses', "Event {$nama} berhasil dihapus.");
    }

    /**
     * Admin UPT hanya boleh menyentuh event yang mencakup unitnya (FR-EVT-02).
     */
    protected function boleh(Request $request, EventAbsen $event): bool
    {
        $pengguna = $request->user();

        if ($pengguna->lintasUnit()) {
            return true;
        }

        if ($event->berlakuUntukSemuaUnit()) {
            // Event lintas unit dibuat peran lintas unit; Admin UPT boleh
            // melihatnya, tetapi tidak boleh mengubahnya.
            return false;
        }

        $cakupanPengguna = UnitKerja::idsDenganTurunan($pengguna->unit_kerja_id);

        return $event->unitKerja
            ->pluck('id')
            ->intersect($cakupanPengguna)
            ->isNotEmpty();
    }
}
