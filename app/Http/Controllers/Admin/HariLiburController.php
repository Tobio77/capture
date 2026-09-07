<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AksiLog;
use App\Http\Controllers\Controller;
use App\Models\HariLibur;
use App\Models\UnitKerja;
use App\Services\LogAktivitasService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Daftar hari libur bertanggal (FR-SET-08).
 *
 * Tanpa layar ini, kalender kerja hanya setengah dapat dipakai: hari kerja
 * pekanan memang dapat diatur pada Setting Unit Kerja, tetapi tanggal merah —
 * yang justru paling sering ditanyakan — tidak akan pernah dapat dimasukkan
 * siapa pun.
 *
 * Cakupannya mengikuti peran. Admin UPT hanya boleh menambahkan libur bagi
 * unitnya sendiri; libur NASIONAL, yang berlaku bagi seluruh dinas, hanya
 * dapat dipasang peran lintas unit — satu orang yang keliru menandai hari
 * kerja sebagai libur nasional akan menandai seluruh absensi hari itu di
 * seluruh provinsi.
 */
class HariLiburController extends Controller
{
    public function __construct(protected LogAktivitasService $log) {}

    public function store(Request $request): RedirectResponse
    {
        $pengguna = $request->user();

        $data = $request->validate([
            'tanggal' => ['required', 'date'],
            'keterangan' => ['required', 'string', 'max:150'],
            'unit_kerja_id' => [
                'nullable',
                'integer',
                Rule::exists('unit_kerja', 'id'),
            ],
        ], attributes: [
            'tanggal' => 'tanggal',
            'keterangan' => 'keterangan',
            'unit_kerja_id' => 'unit kerja',
        ]);

        $unitId = $data['unit_kerja_id'] ?? null;

        /*
         * Admin UPT tidak dapat memasang libur nasional, dan tidak dapat
         * memasangnya bagi unit lain. Keduanya diperiksa di sini, bukan di
         * layar: layar hanya menyembunyikan pilihannya, dan yang disembunyikan
         * masih dapat dikirim.
         */
        if (! $pengguna->lintasUnit()) {
            $cakupan = UnitKerja::idsDenganTurunan($pengguna->unit_kerja_id);

            abort_if(
                $unitId === null || ! in_array($unitId, $cakupan, true),
                403,
                'Hari libur nasional hanya dapat dipasang admin lintas unit.',
            );
        }

        $tanggal = Carbon::parse($data['tanggal'])->startOfDay();

        $sudahAda = HariLibur::query()
            ->whereDate('tanggal', $tanggal)
            ->where('unit_kerja_id', $unitId)
            ->exists();

        if ($sudahAda) {
            return back()->withErrors([
                'tanggal' => 'Tanggal itu sudah terdaftar untuk cakupan yang sama.',
            ]);
        }

        $libur = HariLibur::query()->create([
            'tanggal' => $tanggal,
            'keterangan' => $data['keterangan'],
            'unit_kerja_id' => $unitId,
            'dibuat_oleh' => $pengguna->id,
        ]);

        $this->log->catat(
            AksiLog::Buat,
            sprintf(
                'Menambah hari libur %s — %s (%s).',
                $tanggal->toDateString(),
                $libur->keterangan,
                $unitId === null ? 'seluruh unit' : UnitKerja::query()->whereKey($unitId)->value('nama'),
            ),
            user: $pengguna,
            subjek: $libur,
        );

        return back()->with('sukses', 'Hari libur berhasil ditambahkan.');
    }

    public function destroy(Request $request, HariLibur $hariLibur): RedirectResponse
    {
        $pengguna = $request->user();

        if (! $pengguna->lintasUnit()) {
            $cakupan = UnitKerja::idsDenganTurunan($pengguna->unit_kerja_id);

            abort_if(
                $hariLibur->unit_kerja_id === null
                    || ! in_array($hariLibur->unit_kerja_id, $cakupan, true),
                403,
            );
        }

        $keterangan = "{$hariLibur->tanggal->toDateString()} — {$hariLibur->keterangan}";

        $hariLibur->delete();

        $this->log->catat(
            AksiLog::Hapus,
            "Menghapus hari libur {$keterangan}.",
            user: $pengguna,
        );

        return back()->with('sukses', 'Hari libur berhasil dihapus.');
    }
}
