<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PeranPengguna;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanPenggunaRequest;
use App\Http\Requests\UbahStatusPenggunaRequest;
use App\Models\User;
use App\Services\PenggunaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kelola akun admin (FR-USR-01).
 *
 * Terbatas pada Superadmin — Admin Dinas hanya boleh mengelola perangkat
 * absen (matriks peran SRS §6).
 */
class PenggunaController extends Controller
{
    public function __construct(protected PenggunaService $pengguna) {}

    public function index(): Response
    {
        return Inertia::render('Pengguna/Index', [
            'daftar' => $this->pengguna->daftar(),
            'unit_kerja' => $this->pengguna->unitKerjaTersedia(),
            'peran' => collect(PeranPengguna::cases())
                ->map(fn (PeranPengguna $peran) => [
                    'nilai' => $peran->value,
                    'label' => $peran->label(),
                    'lintas_unit' => $peran->lintasUnit(),
                ]),
        ]);
    }

    public function store(SimpanPenggunaRequest $request): RedirectResponse
    {
        $hasil = $this->pengguna->buat($request->validated(), $request->user());

        /*
         * Kata sandi sementara hanya muncul sekali, di sini. Ia tidak pernah
         * dapat dibaca lagi karena yang tersimpan hanya hash-nya, sehingga
         * Superadmin perlu menyalinnya sebelum menutup dialog.
         */
        return back()->with([
            'sukses' => "Akun {$hasil['pengguna']->nama} berhasil dibuat.",
            'sandi_sementara' => [
                'email' => $hasil['pengguna']->email,
                'sandi' => $hasil['sandi'],
            ],
        ]);
    }

    public function update(SimpanPenggunaRequest $request, User $pengguna): RedirectResponse
    {
        $this->pengguna->perbarui($pengguna, $request->validated(), $request->user());

        return back()->with('sukses', "Akun {$pengguna->nama} berhasil diperbarui.");
    }

    public function ubahStatus(UbahStatusPenggunaRequest $request, User $pengguna): RedirectResponse
    {
        $aktif = $request->boolean('aktif');

        $this->pengguna->ubahStatus($pengguna, $aktif, $request->user());

        return back()->with(
            'sukses',
            "Akun {$pengguna->nama} berhasil ".($aktif ? 'diaktifkan.' : 'dinonaktifkan.'),
        );
    }

    public function resetSandi(Request $request, User $pengguna): RedirectResponse
    {
        $sandi = $this->pengguna->resetSandi($pengguna, $request->user());

        return back()->with([
            'sukses' => "Kata sandi baru untuk {$pengguna->nama} berhasil diterbitkan.",
            'sandi_sementara' => ['email' => $pengguna->email, 'sandi' => $sandi],
        ]);
    }
}
