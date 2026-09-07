<?php

namespace App\Http\Controllers\Admin;

use App\Enums\KompresiFoto;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanSettingAbsenRequest;
use App\Models\HariLibur;
use App\Models\UnitKerja;
use App\Services\SettingAbsenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    public function edit(Request $request): Response
    {
        $pengguna = $request->user();

        /*
         * Hanya libur yang masih relevan yang ditampilkan: tanggal yang sudah
         * lewat lebih dari sebulan tidak lagi dapat ditindaklanjuti, dan
         * daftar yang memanjang setiap tahun berhenti dibaca.
         */
        $libur = HariLibur::query()
            ->with('unitKerja:id,nama')
            ->whereDate('tanggal', '>=', Carbon::today()->subMonth())
            ->when(
                ! $pengguna->lintasUnit(),
                fn ($q) => $q->where(fn ($w) => $w->whereNull('unit_kerja_id')
                    ->orWhereIn('unit_kerja_id', UnitKerja::idsDenganTurunan($pengguna->unit_kerja_id))),
            )
            ->orderBy('tanggal')
            ->limit(60)
            ->get()
            ->map(fn (HariLibur $satu) => [
                'id' => $satu->id,
                'tanggal' => $satu->tanggal->toDateString(),
                'tanggal_panjang' => $satu->tanggal->translatedFormat('l, d F Y'),
                'keterangan' => $satu->keterangan,
                'cakupan' => $satu->unitKerja?->nama ?? 'Seluruh unit kerja',
                'nasional' => $satu->nasional(),
                'lampau' => $satu->tanggal->isPast(),
            ]);

        return Inertia::render('Setting/Absen', [
            'setting' => $this->setting->ambil(),
            'hari_libur' => $libur,
            'unit_kerja_libur' => UnitKerja::query()
                ->aktif()
                ->when(
                    ! $pengguna->lintasUnit(),
                    fn ($q) => $q->whereIn('id', UnitKerja::idsDenganTurunan($pengguna->unit_kerja_id)),
                )
                ->orderBy('nama')
                ->get(['id', 'nama'])
                ->map(fn (UnitKerja $u) => ['nilai' => $u->id, 'label' => $u->nama]),
            'boleh_libur_nasional' => $pengguna->lintasUnit(),
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
