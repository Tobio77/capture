<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StatusEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanEventRequest;
use App\Models\EventAbsen;
use App\Services\EksporService;
use App\Services\EventAbsenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Daftar dan pengelolaan event absensi (FR-EVT-01, FR-EVT-02).
 *
 * Sejak S49 setiap event berlaku bagi seluruh dinas, sehingga tidak ada lagi
 * pemeriksaan "apakah event ini menyentuh unit saya" di sini. Yang tersisa
 * adalah pembedaan peran yang jauh lebih sederhana, dan ia ditegakkan pada
 * route: seluruh peran admin boleh MEMBACA daftar dan detailnya, hanya peran
 * lintas unit yang boleh membuat, mengubah, menutup, dan menghapus.
 */
class EventController extends Controller
{
    public function __construct(
        protected EventAbsenService $event,
        protected EksporService $ekspor,
    ) {}

    public function index(Request $request): Response
    {
        $filter = $request->only(['cari', 'status', 'dari', 'sampai']);

        return Inertia::render('Event/Index', [
            'daftar' => $this->event->daftar($request->user(), $filter),
            'filter' => array_map(fn ($nilai) => $nilai ?? '', $filter + [
                'cari' => '', 'status' => '', 'dari' => '', 'sampai' => '',
            ]),
            'status_pilihan' => collect(StatusEvent::cases())
                ->map(fn (StatusEvent $status) => [
                    'nilai' => $status->value,
                    'label' => $status->label(),
                ]),
            'nilai_awal' => $this->event->nilaiAwal(),

            /*
             * Menentukan tampil-tidaknya tombol buat/ubah/tutup/hapus. Dihitung
             * di server dan dikirim sebagai prop, bukan diturunkan ulang di
             * peramban: route sudah menolak yang tidak berhak, dan tombol yang
             * tampil tanpa hak hanya menyesatkan admin unit.
             */
            'boleh_kelola' => $request->user()->lintasUnit(),
        ]);
    }

    /**
     * Unduh daftar event sebagai CSV atau PDF, mengikuti penyaringan yang
     * sedang dipakai — bukan hanya halaman yang sedang dibuka.
     */
    public function ekspor(Request $request): SymfonyResponse
    {
        $pengguna = $request->user();
        $filter = $request->only(['cari', 'status', 'dari', 'sampai']);
        $baris = $this->event->semua($pengguna, $filter);

        $nama = 'daftar-event-'.now()->format('Ymd-Hi');

        if ($request->string('format')->toString() === 'pdf') {
            return $this->ekspor->unduhPdf('cetak.event', [
                'baris' => $baris,
                // Angka perangkat dan absen sudah dibatasi cakupan pengunduh.
                'cakupan' => $pengguna->lintasUnit()
                    ? 'Seluruh unit kerja'
                    : ($pengguna->unitKerja?->nama ?? 'Unit kerja sendiri'),
                'keterangan' => $baris->count().' event pada penyaringan ini',
            ], "{$nama}.pdf");
        }

        return $this->ekspor->unduhCsv(
            $this->ekspor->csv(
                ['Nama Event', 'Tanggal', 'Jam Mulai', 'Toleransi (menit)', 'Perangkat', 'Absen Masuk', 'Status'],
                $baris->map(fn (array $isi) => [
                    $isi['nama'],
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

        return back()->with('sukses', "Event {$event->nama} berhasil dibuka untuk seluruh unit kerja.");
    }

    public function update(SimpanEventRequest $request, EventAbsen $event): RedirectResponse
    {
        // Event yang sudah ditutup adalah catatan riwayat; perubahannya akan
        // menggeser makna absensi yang terlanjur tercatat di bawahnya.
        abort_unless($event->aktif(), 403, 'Event yang sudah ditutup tidak dapat diubah.');

        $this->event->perbarui($event, $request->validated(), $request->user());

        return back()->with('sukses', "Event {$event->nama} berhasil diperbarui.");
    }

    /**
     * Detail event: perangkat yang melayaninya beserta unit dan IP masing-
     * masing, jumlah absen masuk, dan status entry (FR-EVT-05).
     *
     * Dijawab sebagai JSON, bukan halaman Inertia, karena dimuat oleh modal
     * di atas daftar event yang sudah tampil.
     */
    public function detail(Request $request, EventAbsen $event): JsonResponse
    {
        return response()->json($this->event->detail($event, $request->user()->cakupanUnit()));
    }

    /**
     * Tutup entry event (FR-EVT-04).
     */
    public function tutup(Request $request, EventAbsen $event): RedirectResponse
    {
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
        abort_unless(
            $this->event->dapatDihapus($event),
            403,
            'Event yang sudah memiliki absensi tidak dapat dihapus.',
        );

        $nama = $event->nama;

        $this->event->hapus($event, $request->user());

        return back()->with('sukses', "Event {$nama} berhasil dihapus.");
    }
}
