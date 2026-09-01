<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Services\SinkronisasiPegawaiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kelola Pegawai (FR-PEG-01 s.d. FR-PEG-04).
 *
 * Data pegawai baca-saja di SI-ABSEN; satu-satunya aksi tulis di sini adalah
 * memicu sinkronisasi dari WORKA.
 */
class PegawaiController extends Controller
{
    public function __construct(protected SinkronisasiPegawaiService $sinkronisasi) {}

    public function index(Request $request): Response
    {
        $pengguna = $request->user();

        $pegawai = Pegawai::query()
            ->with('unitKerja:id,kode,nama')
            ->cari($request->string('cari')->toString() ?: null)

            /*
             * Cakupan unit selalu menyertakan turunannya: pegawai menaut ke
             * seksi/subbag, sedangkan yang dipilih admin adalah UPT/bidang di
             * atasnya. Padanan persis akan mengosongkan daftar UPT (SDD §3.1).
             */

            // Admin UPT hanya melihat pegawai unit kerjanya sendiri (SRS §6).
            ->when(
                ! $pengguna->lintasUnit(),
                fn ($q) => $q->whereIn(
                    'unit_kerja_id',
                    UnitKerja::idsDenganTurunan($pengguna->unit_kerja_id),
                ),
            )
            ->when(
                $request->filled('unit_kerja_id'),
                fn ($q) => $q->whereIn(
                    'unit_kerja_id',
                    UnitKerja::idsDenganTurunan($request->integer('unit_kerja_id')),
                ),
            )
            ->when(
                $request->string('status_foto')->toString() === 'terdaftar',
                fn ($q) => $q->where('wajah_terdaftar', true),
            )
            ->when(
                $request->string('status_foto')->toString() === 'belum',
                fn ($q) => $q->where('wajah_terdaftar', false),
            )
            ->when(
                $request->string('status')->toString() === 'aktif',
                fn ($q) => $q->where('aktif', true),
            )
            ->when(
                $request->string('status')->toString() === 'nonaktif',
                fn ($q) => $q->where('aktif', false),
            )
            ->orderBy('nama')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Pegawai $p) => [
                'id' => $p->id,
                'nip' => $p->nip,
                'nama' => $p->nama,
                'unit_kerja' => $p->unitKerja?->only(['id', 'kode', 'nama']),
                'jabatan' => $p->jabatan,
                'uid_kartu' => $p->uid_kartu,
                'wajah_terdaftar' => $p->wajah_terdaftar,
                'wajah_didaftarkan_at' => $p->wajah_didaftarkan_at?->toIso8601String(),
                'aktif' => $p->aktif,
                'sumber_sinkron_terakhir' => $p->sumber_sinkron_terakhir?->toIso8601String(),
            ]);

        return Inertia::render('Pegawai/Index', [
            'pegawai' => $pegawai,
            // Penyaring memakai unit level teratas saja; seksi/subbag ikut
            // terjaring lewat cakupan turunan di atas.
            'unit_kerja' => UnitKerja::query()
                ->levelTeratas()
                ->orderBy('nama')
                ->get(['id', 'kode', 'nama'])
                ->when(
                    ! $pengguna->lintasUnit(),
                    fn ($daftar) => $daftar->filter(fn (UnitKerja $unit) => in_array(
                        $pengguna->unit_kerja_id,
                        UnitKerja::idsDenganTurunan($unit->id),
                        true,
                    )),
                )
                ->values(),
            'filter' => [
                'cari' => $request->string('cari')->toString(),
                'unit_kerja_id' => $request->string('unit_kerja_id')->toString(),
                'status_foto' => $request->string('status_foto')->toString(),
                'status' => $request->string('status')->toString(),
            ],
            'dapat_sinkron' => $pengguna->lintasUnit(),

            // Tanpa uji koneksi: halaman menariknya sendiri lewat
            // /admin/pegawai/status agar tabel tidak menunggu WORKA menjawab.
            'status_sinkron' => $this->sinkronisasi->statusSinkron(cekKoneksi: false),
        ]);
    }

    /**
     * Picu sinkronisasi manual dari panel admin (FR-PEG-01).
     */
    public function sinkron(Request $request): RedirectResponse
    {
        $penuh = $request->boolean('penuh');

        $hasil = $penuh
            ? $this->sinkronisasi->sinkronPenuh($request->user())
            : $this->sinkronisasi->sinkronInkremental($request->user());

        return back()->with(
            $hasil->berhasil() ? 'sukses' : 'gagal',
            $hasil->berhasil()
                ? $this->ringkasan($hasil->toArray())
                : $hasil->error,
        );
    }

    /**
     * Status sinkronisasi dalam JSON, untuk polling dari panel admin.
     */
    public function statusSinkron(): JsonResponse
    {
        return response()->json($this->sinkronisasi->statusSinkron());
    }

    /**
     * @param  array<string, mixed>  $hasil
     */
    protected function ringkasan(array $hasil): string
    {
        if ($hasil['status'] === 'tidak_ada_perubahan') {
            return $hasil['pesan'];
        }

        return sprintf(
            'Berhasil: %d ditambahkan, %d diperbarui, %d dinonaktifkan (%.1f detik).',
            $hasil['ditambahkan'],
            $hasil['diperbarui'],
            $hasil['dinonaktifkan'],
            $hasil['durasi_detik'],
        );
    }
}
