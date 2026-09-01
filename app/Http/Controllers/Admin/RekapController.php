<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\UnitKerja;
use App\Services\AbsensiService;
use App\Services\EventAbsenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rekap Absen per event (FR-REK-01 s.d. FR-REK-03).
 */
class RekapController extends Controller
{
    public function __construct(
        protected EventAbsenService $event,
        protected AbsensiService $absensi,
    ) {}

    public function index(Request $request): Response
    {
        $pengguna = $request->user();
        $daftarEvent = $this->event->daftar($pengguna);

        // Tanpa pilihan eksplisit, event terbaru yang ditampilkan — admin
        // hampir selalu membuka halaman ini untuk kegiatan yang sedang atau
        // baru saja berjalan.
        $terpilih = $request->filled('event_absen_id')
            ? $this->eventTerpilih($request, (int) $request->integer('event_absen_id'))
            : $this->eventPertama($daftarEvent);

        $rekap = $terpilih === null
            ? collect()
            : $this->absensi->rekap($terpilih, $this->cakupan($request));

        return Inertia::render('Rekap/Index', [
            'daftar_event' => $daftarEvent->map(fn (array $event) => [
                'id' => $event['id'],
                'nama' => $event['nama'],
                'tanggal' => $event['tanggal'],
                'jam_mulai' => $event['jam_mulai'],
                'status' => $event['status'],
                'status_label' => $event['status_label'],
                'cakupan_label' => $event['cakupan_label'],
            ]),
            'event' => $terpilih === null ? null : [
                'id' => $terpilih->id,
                'nama' => $terpilih->nama,
                'tanggal' => $terpilih->tanggal->toDateString(),
                'jam_mulai' => substr((string) $terpilih->jam_mulai, 0, 5),
                'toleransi_menit' => $terpilih->toleransi_menit,
                'status' => $terpilih->status->value,
                'status_label' => $terpilih->status->label(),
            ],
            'rekap' => $rekap,
            'ringkasan' => $this->absensi->ringkasanRekap($rekap),
        ]);
    }

    /**
     * Rekap terkini sebagai JSON, dipakai halaman untuk memperbarui tabelnya
     * selama event berlangsung (FR-REK-01).
     */
    public function data(Request $request, EventAbsen $event): JsonResponse
    {
        abort_unless($this->dapatMelihat($request, $event), 403);

        $rekap = $this->absensi->rekap($event, $this->cakupan($request));

        return response()->json([
            'rekap' => $rekap,
            'ringkasan' => $this->absensi->ringkasanRekap($rekap),
            'status' => $event->status->value,
            'status_label' => $event->status->label(),
        ]);
    }

    /**
     * Foto absen untuk panel admin (NFR-04).
     *
     * Terpisah dari route kiosk: yang ini dibatasi peran admin dan cakupan
     * unitnya, bukan perangkat yang sedang melayani event.
     */
    public function foto(Request $request, Absensi $absensi): StreamedResponse
    {
        $cakupan = $this->cakupan($request);

        abort_unless(
            $cakupan === null || in_array($absensi->pegawai?->unit_kerja_id, $cakupan, true),
            403,
        );
        abort_if($absensi->foto_path === null, 404);

        $disk = Storage::disk(AbsensiService::DISK);

        abort_unless($disk->exists($absensi->foto_path), 404);

        return $disk->response(
            $absensi->foto_path,
            headers: ['Cache-Control' => 'private, max-age=300'],
        );
    }

    /**
     * Cakupan unit pengguna, atau null bila tidak perlu disaring (FR-REK-02).
     *
     * @return array<int, int>|null
     */
    protected function cakupan(Request $request): ?array
    {
        $pengguna = $request->user();

        return $pengguna->lintasUnit()
            ? null
            : UnitKerja::idsDenganTurunan($pengguna->unit_kerja_id);
    }

    protected function eventTerpilih(Request $request, int $id): ?EventAbsen
    {
        $event = EventAbsen::query()->with('unitKerja:id')->find($id);

        if ($event === null || ! $this->dapatMelihat($request, $event)) {
            return null;
        }

        return $event;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $daftarEvent
     */
    protected function eventPertama(Collection $daftarEvent): ?EventAbsen
    {
        $pertama = $daftarEvent->first();

        return $pertama === null
            ? null
            : EventAbsen::query()->with('unitKerja:id')->find($pertama['id']);
    }

    /**
     * Admin UPT boleh membuka rekap event yang menyentuh unitnya, termasuk
     * event bercakupan semua unit — isinya yang dibatasi, bukan aksesnya.
     */
    protected function dapatMelihat(Request $request, EventAbsen $event): bool
    {
        $pengguna = $request->user();

        if ($pengguna->lintasUnit() || $event->berlakuUntukSemuaUnit()) {
            return true;
        }

        return $event->unitKerja
            ->pluck('id')
            ->intersect(UnitKerja::idsDenganTurunan($pengguna->unit_kerja_id))
            ->isNotEmpty();
    }
}
