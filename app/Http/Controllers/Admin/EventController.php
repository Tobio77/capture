<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CakupanEvent;
use App\Enums\StatusEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanEventRequest;
use App\Models\EventAbsen;
use App\Models\KodeUnitEvent;
use App\Models\UnitKerja;
use App\Services\EksporService;
use App\Services\EventAbsenService;
use App\Services\KodeUnitEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Daftar dan pengelolaan event absensi (FR-EVT-01, FR-EVT-02).
 *
 * Menutup event (FR-EVT-04) dan detail kiosk terhubung (FR-EVT-03, FR-EVT-05)
 * dikerjakan pada S11 dan S12.
 */
class EventController extends Controller
{
    public function __construct(
        protected EventAbsenService $event,
        protected EksporService $ekspor,
        protected KodeUnitEventService $kodeUnit,
    ) {}

    public function index(Request $request): Response
    {
        $pengguna = $request->user();

        $filter = $request->only(['cari', 'status', 'unit_kerja_id', 'dari', 'sampai']);

        return Inertia::render('Event/Index', [
            'daftar' => $this->event->daftar($pengguna, $filter),
            'filter' => array_map(fn ($nilai) => $nilai ?? '', $filter + [
                'cari' => '', 'status' => '', 'unit_kerja_id' => '', 'dari' => '', 'sampai' => '',
            ]),
            'status_pilihan' => collect(StatusEvent::cases())
                ->map(fn (StatusEvent $status) => [
                    'nilai' => $status->value,
                    'label' => $status->label(),
                ]),
            'unit_kerja' => $this->event->unitKerjaTersedia($pengguna),
            'nilai_awal' => $this->event->nilaiAwal(),

            /*
             * Cakupan yang melampaui satu unit — "Semua Unit" dan "Wilayah
             * Kerja Surabaya" — hanya untuk peran lintas unit (FR-EVT-01,
             * FR-EVT-02).
             */
            'boleh_semua_unit' => $pengguna->lintasUnit(),
            'cakupan_semua_unit' => CakupanEvent::SemuaUnit->value,
            'cakupan_unit' => CakupanEvent::Unit->value,

            /*
             * Cakupan bawaan sistem: daftar unitnya tertanam pada enum, bukan
             * dicentang admin. Dikirim beserta unit penyusunnya supaya
             * formulir dapat memperlihatkan apa saja yang tercakup sebelum
             * admin menyimpan — dan supaya kekeliruan pemetaan kode unit
             * ketahuan di layar, bukan setelah event berjalan.
             */
            'cakupan_tertanam' => collect(CakupanEvent::cases())
                ->filter(fn (CakupanEvent $cakupan) => $cakupan->unitTertanam())
                ->map(fn (CakupanEvent $cakupan) => [
                    'nilai' => $cakupan->value,
                    'label' => $cakupan->label(),
                    'unit_kerja' => UnitKerja::query()
                        ->whereIn('kode', $cakupan->kodeUnitTertanam())
                        ->orderBy('nama')
                        ->get(['id', 'kode', 'nama']),
                ])
                ->values(),
        ]);
    }

    /**
     * Unduh daftar event sebagai CSV atau PDF, mengikuti penyaringan yang
     * sedang dipakai — bukan hanya halaman yang sedang dibuka.
     */
    public function ekspor(Request $request): SymfonyResponse
    {
        $pengguna = $request->user();
        $filter = $request->only(['cari', 'status', 'unit_kerja_id', 'dari', 'sampai']);
        $baris = $this->event->semua($pengguna, $filter);

        $nama = 'daftar-event-'.now()->format('Ymd-Hi');
        $cakupan = $pengguna->lintasUnit()
            ? 'Seluruh unit kerja'
            : ($pengguna->unitKerja?->nama ?? 'Tanpa unit kerja');

        if ($request->string('format')->toString() === 'pdf') {
            return $this->ekspor->unduhPdf('cetak.event', [
                'baris' => $baris,
                'cakupan' => $cakupan,
                'keterangan' => $baris->count().' event pada penyaringan ini',
            ], "{$nama}.pdf");
        }

        return $this->ekspor->unduhCsv(
            $this->ekspor->csv(
                ['Nama Event', 'Cakupan', 'Tanggal', 'Jam Mulai', 'Toleransi (menit)', 'Perangkat', 'Absen Masuk', 'Status'],
                $baris->map(fn (array $isi) => [
                    $isi['nama'],
                    $isi['cakupan'] === 'semua_unit'
                        ? 'Semua Unit'
                        : collect($isi['unit_kerja'])->pluck('kode')->join(', '),
                    $isi['tanggal'],
                    $isi['jam_mulai'],
                    $isi['toleransi_menit'],
                    $isi['jumlah_kiosk'],
                    $isi['jumlah_absensi'],
                    $isi['status_label'],
                ]),
            ),
            "{$nama}.csv",
        );
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
     * Detail event: kiosk terhubung, jumlah absen masuk, status entry
     * (FR-EVT-05).
     *
     * Dijawab sebagai JSON, bukan halaman Inertia, karena dimuat oleh modal
     * di atas daftar event yang sudah tampil.
     */
    public function detail(Request $request, EventAbsen $event): JsonResponse
    {
        abort_unless($this->dapatMelihat($request, $event), 403);

        return response()->json(
            $this->event->detail($event, $this->boleh($request, $event)),
        );
    }

    /**
     * Terbitkan ulang kode unit kerja sebuah event (FR-EVT-03).
     *
     * Berwenang atas kode = berwenang atas eventnya, yaitu pagar yang sama
     * dengan mengubah dan menutup event: Superadmin serta Admin Dinas untuk
     * event mana pun, Admin UPT hanya untuk event yang menyentuh unitnya
     * sendiri (matriks peran SRS §6).
     */
    public function resetKode(Request $request, EventAbsen $event, KodeUnitEvent $kode): RedirectResponse
    {
        abort_unless($this->boleh($request, $event), 403);
        abort_unless($kode->event_absen_id === $event->id, 404);

        // Kode hanya berguna selama entry masih menerima tap; menerbitkan
        // ulang pada event yang sudah ditutup hanya membingungkan petugas.
        abort_unless($event->aktif(), 403, 'Event ini sudah ditutup.');

        $baru = $this->kodeUnit->reset($kode, $request->user());

        return back()->with('sukses', sprintf(
            'Kode unit %s diganti menjadi %s. Perangkat yang sudah bergabung tidak terputus.',
            $baru->unitKerja?->kode ?? 'terpilih',
            KodeUnitEventService::format($baru->kode),
        ));
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
            "Entry event {$event->nama} ditutup. Tap baru pada perangkat absen akan ditolak.",
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
     * Melihat lebih longgar daripada mengubah: Admin UPT boleh membuka detail
     * event bercakupan semua unit yang menyentuh unitnya, walau tidak boleh
     * mengubahnya.
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
