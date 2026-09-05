<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\UnitKerja;
use App\Services\AbsensiService;
use App\Services\AbsenUmumService;
use App\Services\EksporService;
use App\Services\EventAbsenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rekap Absen per event (FR-REK-01 s.d. FR-REK-03).
 */
class RekapController extends Controller
{
    public function __construct(
        protected EventAbsenService $event,
        protected AbsensiService $absensi,
        protected EksporService $ekspor,
        protected AbsenUmumService $absenUmum,
    ) {}

    /**
     * Rekap Absen, dua tab: kegiatan dan harian.
     *
     * Absen umum sudah punya menunya sendiri untuk memantau sesi yang sedang
     * berjalan, tetapi orang yang hendak MEMBACA rekap mencarinya di menu
     * Rekap Absen — dan sebelumnya hanya menemukan kegiatan di sana, seolah
     * kehadiran harian tidak pernah direkap.
     *
     * Tab harian tidak menyalin apa pun: barisnya diminta ke
     * {@see AbsenUmumService::rekapHarian()}, sumber yang sama yang dipakai
     * halaman Absen Umum, dan digambar oleh komponen tabel yang sama pula.
     */
    public function index(Request $request): Response
    {
        return $request->string('tab')->toString() === 'umum'
            ? $this->umum($request)
            : $this->kegiatan($request);
    }

    /**
     * Tab Rekap Umum — kehadiran harian per unit kerja dan tanggal.
     */
    protected function umum(Request $request): Response
    {
        $pengguna = $request->user();
        $unitTersedia = $this->absenUmum->unitTersedia($pengguna);

        $unitId = $this->absenUmum->unitTerpilih(
            $pengguna,
            $request->integer('unit_kerja_id') ?: $unitTersedia->first()['id'] ?? null,
        );

        $tanggal = $request->string('tanggal')->toString() === ''
            ? Carbon::today()
            : Carbon::parse($request->string('tanggal')->toString())->startOfDay();

        $cari = $request->string('cari')->toString();
        $rekap = $this->absenUmum->rekapHarian($pengguna, $unitId, $tanggal, $cari);
        $sesi = $rekap['sesi'];

        return Inertia::render('Rekap/Index', [
            'tab' => 'umum',
            'daftar_event' => [],
            'event' => null,
            'rekap' => [],
            'ringkasan' => $rekap['ringkasan'],
            'umum' => [
                'unit_kerja' => $unitTersedia->values(),
                'filter' => [
                    'unit_kerja_id' => $unitId,
                    'tanggal' => $tanggal->toDateString(),
                    'cari' => $cari,
                ],
                'sesi' => $sesi === null ? null : [
                    'nama' => $sesi->nama,
                    'tanggal' => $sesi->tanggal->toDateString(),
                    'jam_mulai' => substr((string) $sesi->jam_mulai, 0, 5),
                    'toleransi_menit' => $sesi->toleransi_menit,
                    'aktif' => $sesi->aktif(),
                ],
                'baris' => $rekap['baris']->values(),
            ],
        ]);
    }

    /**
     * Tab Rekap Event — kehadiran satu kegiatan.
     */
    protected function kegiatan(Request $request): Response
    {
        $pengguna = $request->user();
        $daftarEvent = $this->event->opsiEvent($pengguna);

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
            'tab' => 'event',
            'umum' => null,
            'daftar_event' => $daftarEvent,
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
     * Unduh rekap sebuah event sebagai CSV atau PDF (FR-REK-03).
     */
    public function ekspor(Request $request, EventAbsen $event): SymfonyResponse
    {
        abort_unless($this->dapatMelihat($request, $event), 403);

        $rekap = $this->absensi->rekap($event, $this->cakupan($request));
        $pengguna = $request->user();

        $nama = 'rekap-'.str($event->nama)->slug().'-'.$event->tanggal->format('Ymd');
        $cakupan = $pengguna->lintasUnit()
            ? 'Seluruh unit kerja'
            : ($pengguna->unitKerja?->nama ?? 'Tanpa unit kerja');

        if ($request->string('format')->toString() === 'pdf') {
            return $this->ekspor->unduhPdf('cetak.rekap', [
                'baris' => $rekap,
                'ringkasan' => $this->absensi->ringkasanRekap($rekap),
                'cakupan' => $cakupan,
                'event' => [
                    'nama' => $event->nama,
                    'tanggal' => $event->tanggal->translatedFormat('l, d F Y'),
                    'jam_mulai' => substr((string) $event->jam_mulai, 0, 5),
                    'toleransi_menit' => $event->toleransi_menit,
                    'status_label' => $event->status->label(),
                ],
            ], "{$nama}.pdf");
        }

        return $this->ekspor->unduhCsv(
            $this->ekspor->csv(
                ['NIP', 'Nama', 'Unit Kerja', 'Jam Masuk', 'Jam Pulang', 'Metode', 'Status'],
                $rekap->map(fn (array $isi) => [
                    $isi['nip'],
                    $isi['nama'],
                    $isi['unit_kerja'] ?? '',
                    $isi['jam_masuk'] ?? '',
                    $isi['jam_pulang'] ?? '',
                    $isi['metode'],
                    $isi['status_label'] ?? '',
                ]),
            ),
            "{$nama}.csv",
        );
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
