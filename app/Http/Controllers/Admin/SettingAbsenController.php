<?php

namespace App\Http\Controllers\Admin;

use App\Enums\KompresiFoto;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanSettingAbsenRequest;
use App\Services\SettingAbsenService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setting Absen — pengaturan global sistem (FR-SET-01 s.d. FR-SET-04).
 *
 * Bukan pengaturan per unit kerja, sehingga terbatas pada peran lintas unit
 * (matriks peran SRS §6); pembatasannya dipasang pada route.
 */
class SettingAbsenController extends Controller
{
    public function __construct(protected SettingAbsenService $setting) {}

    public function edit(): Response
    {
        return Inertia::render('Setting/Absen', [
            'setting' => $this->setting->ambil(),
            'preset_kompresi' => KompresiFoto::daftar(),
            'batas' => [
                'ambang_min' => SettingAbsenService::AMBANG_MIN,
                'ambang_maks' => SettingAbsenService::AMBANG_MAKS,
                'toleransi_maks' => SettingAbsenService::TOLERANSI_MAKS_MENIT,
            ],
        ]);
    }

    public function update(SimpanSettingAbsenRequest $request): RedirectResponse
    {
        $this->setting->simpan($request->validated(), $request->user());

        return back()->with('sukses', 'Setting Absen berhasil disimpan.');
    }
}
