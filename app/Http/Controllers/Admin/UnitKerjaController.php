<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanUnitKerjaRequest;
use App\Http\Requests\UbahStatusUnitKerjaRequest;
use App\Models\UnitKerja;
use App\Services\UnitKerjaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UnitKerjaController extends Controller
{
    public function __construct(protected UnitKerjaService $unitKerja) {}

    /**
     * Daftar unit kerja level teratas beserta jumlah pegawai dan kiosk
     * se-turunannya (FR-UNIT-02).
     * Admin UPT dapat melihat daftar ini, tetapi tanpa aksi ubah (SRS §6).
     */
    public function index(Request $request): Response
    {
        $filter = $request->only(['cari', 'status']);

        return Inertia::render('UnitKerja/Index', [
            'daftar' => $this->unitKerja->daftar($request->user(), $filter),
            'filter' => ['cari' => '', 'status' => ''] + array_filter($filter, fn ($n) => $n !== null),
            'dapat_mengubah' => $request->user()->lintasUnit(),
        ]);
    }

    public function store(SimpanUnitKerjaRequest $request): RedirectResponse
    {
        $unitKerja = $this->unitKerja->buat($request->validated(), $request->user());

        return back()->with('sukses', "Unit kerja {$unitKerja->kode} berhasil ditambahkan.");
    }

    public function update(SimpanUnitKerjaRequest $request, UnitKerja $unitKerja): RedirectResponse
    {
        $this->unitKerja->perbarui($unitKerja, $request->validated(), $request->user());

        return back()->with('sukses', "Unit kerja {$unitKerja->kode} berhasil diperbarui.");
    }

    /**
     * Aktifkan atau nonaktifkan unit kerja (FR-UNIT-01).
     */
    public function ubahStatus(UbahStatusUnitKerjaRequest $request, UnitKerja $unitKerja): RedirectResponse
    {
        $aktif = $request->boolean('aktif');

        $this->unitKerja->ubahStatus($unitKerja, $aktif, $request->user());

        return back()->with(
            'sukses',
            "Unit kerja {$unitKerja->kode} berhasil ".($aktif ? 'diaktifkan.' : 'dinonaktifkan.'),
        );
    }
}
