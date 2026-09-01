<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanPerangkatRequest;
use App\Models\Kiosk;
use App\Services\PerangkatAbsenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kelola perangkat absen (FR-USR-02, FR-USR-03).
 *
 * Terbuka bagi Superadmin dan Admin Dinas — matriks SRS §6 memberi Admin
 * Dinas hak "Ya (kiosk saja)" pada Kelola User/Role.
 */
class PerangkatAbsenController extends Controller
{
    public function __construct(protected PerangkatAbsenService $perangkat) {}

    public function index(): Response
    {
        return Inertia::render('Perangkat/Index', [
            'daftar' => $this->perangkat->daftar(),
            'unit_kerja' => $this->perangkat->unitKerjaTersedia(),
        ]);
    }

    public function store(SimpanPerangkatRequest $request): RedirectResponse
    {
        $hasil = $this->perangkat->daftarkan($request->validated(), $request->user());

        return back()->with([
            'sukses' => "Perangkat {$hasil['perangkat']->nama_titik} berhasil didaftarkan.",
            'kode_aktivasi' => [
                'nama_titik' => $hasil['perangkat']->nama_titik,
                'kode' => $hasil['kode'],
            ],
        ]);
    }

    public function update(SimpanPerangkatRequest $request, Kiosk $perangkat): RedirectResponse
    {
        $this->perangkat->perbarui($perangkat, $request->validated(), $request->user());

        return back()->with('sukses', "Perangkat {$perangkat->nama_titik} berhasil diperbarui.");
    }

    /**
     * Terbitkan kode aktivasi baru, misalnya karena kode lama kedaluwarsa.
     */
    public function terbitkanKode(Request $request, Kiosk $perangkat): RedirectResponse
    {
        $kode = $this->perangkat->terbitkanKode($perangkat, $request->user());

        return back()->with([
            'sukses' => "Kode aktivasi baru untuk {$perangkat->nama_titik} berhasil diterbitkan.",
            'kode_aktivasi' => ['nama_titik' => $perangkat->nama_titik, 'kode' => $kode],
        ]);
    }

    /**
     * Cabut device token dari panel admin (FR-USR-02).
     */
    public function cabutToken(Request $request, Kiosk $perangkat): RedirectResponse
    {
        $this->perangkat->cabutToken($perangkat, $request->user());

        return back()->with(
            'sukses',
            "Akses perangkat {$perangkat->nama_titik} dicabut. Perangkat harus diaktifkan ulang.",
        );
    }

    public function ubahStatus(Request $request, Kiosk $perangkat): RedirectResponse
    {
        $data = $request->validate(['aktif' => ['required', 'boolean']]);

        $this->perangkat->ubahStatus($perangkat, (bool) $data['aktif'], $request->user());

        return back()->with(
            'sukses',
            "Perangkat {$perangkat->nama_titik} berhasil "
                .($data['aktif'] ? 'diaktifkan.' : 'dinonaktifkan dan aksesnya dicabut.'),
        );
    }

    /**
     * Riwayat aktivasi dan pencabutan sebuah perangkat (FR-USR-03).
     */
    public function riwayat(Kiosk $perangkat): JsonResponse
    {
        return response()->json(['riwayat' => $this->perangkat->riwayat($perangkat)]);
    }
}
