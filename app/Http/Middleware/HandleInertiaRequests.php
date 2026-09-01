<?php

namespace App\Http\Middleware;

use App\Support\MenuNavigasi;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $pengguna = $request->user();

        return [
            ...parent::share($request),
            'app' => [
                'nama' => config('app.name'),
            ],
            'auth' => [
                'pengguna' => $pengguna ? [
                    'id' => $pengguna->id,
                    'nama' => $pengguna->nama,
                    'email' => $pengguna->email,
                    'role' => $pengguna->role->value,
                    'role_label' => $pengguna->role->label(),
                    'lintas_unit' => $pengguna->lintasUnit(),
                    'unit_kerja' => $pengguna->unitKerja?->only(['id', 'kode', 'nama']),
                ] : null,
            ],
            /*
             * Ditulis sebagai closure karena middleware kiosk berjalan setelah
             * middleware Inertia — perangkat baru dikenali saat respons dirender.
             */
            'kiosk' => fn () => $request->kiosk() ? [
                'id' => $request->kiosk()->id,
                'nama_titik' => $request->kiosk()->nama_titik,
                'status' => $request->kiosk()->status->value,
                'ip_terakhir' => $request->kiosk()->ip_terakhir,
                'diaktifkan_pada' => $request->kiosk()->diaktifkan_pada?->toIso8601String(),
                'unit_kerja' => $request->kiosk()->unitKerja?->only(['id', 'kode', 'nama']),
            ] : null,
            'menu' => $pengguna ? MenuNavigasi::untuk($pengguna) : [],
            'rute_saat_ini' => $request->route()?->getName(),
            'flash' => [
                'sukses' => fn () => $request->session()->get('sukses'),
                'gagal' => fn () => $request->session()->get('gagal'),

                /*
                 * Kata sandi sementara akun admin (FR-USR-01). Hanya lewat
                 * flash, tidak pernah tersimpan: yang ada di basis data hanya
                 * hash-nya, sehingga nilainya tidak dapat ditampilkan lagi
                 * setelah halaman berpindah.
                 */
                'sandi_sementara' => fn () => $request->session()->get('sandi_sementara'),
            ],
        ];
    }
}
