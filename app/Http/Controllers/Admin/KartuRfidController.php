<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DaftarkanKartuRequest;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Services\KartuRfidService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pendaftaran kartu RFID pegawai (FR-TAP-03).
 *
 * Reader di lokasi mengeluarkan UID kartu, bukan NIP, sehingga kartu harus
 * ditautkan lebih dulu sebelum dapat dipakai men-tap.
 */
class KartuRfidController extends Controller
{
    public function __construct(protected KartuRfidService $kartu) {}

    public function store(DaftarkanKartuRequest $request, Pegawai $pegawai): RedirectResponse
    {
        $this->kartu->daftarkan($pegawai, $request->string('uid_kartu')->toString(), $request->user());

        return back()->with('sukses', "Kartu RFID {$pegawai->nama} berhasil didaftarkan.");
    }

    public function destroy(Request $request, Pegawai $pegawai): RedirectResponse
    {
        abort_unless($this->boleh($request, $pegawai), 403);

        $this->kartu->cabut($pegawai, $request->user());

        return back()->with('sukses', "Kartu RFID {$pegawai->nama} berhasil dicabut.");
    }

    /**
     * Admin UPT hanya boleh menyentuh pegawai unitnya sendiri (SRS §6).
     */
    protected function boleh(Request $request, Pegawai $pegawai): bool
    {
        $pengguna = $request->user();

        return $pengguna->lintasUnit()
            || in_array(
                $pegawai->unit_kerja_id,
                UnitKerja::idsDenganTurunan($pengguna->unit_kerja_id),
                true,
            );
    }
}
