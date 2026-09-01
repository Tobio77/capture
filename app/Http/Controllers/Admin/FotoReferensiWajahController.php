<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DaftarkanWajahRequest;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Services\FotoReferensiWajahService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pendaftaran foto referensi wajah pegawai (FR-PEG-05).
 */
class FotoReferensiWajahController extends Controller
{
    public function __construct(protected FotoReferensiWajahService $wajah) {}

    public function store(DaftarkanWajahRequest $request, Pegawai $pegawai): RedirectResponse
    {
        $this->wajah->daftarkan(
            $pegawai,
            $request->file('foto'),
            $request->input('embedding'),
            $request->user(),
        );

        return back()->with('sukses', "Foto referensi wajah {$pegawai->nama} berhasil disimpan.");
    }

    public function destroy(Request $request, Pegawai $pegawai): RedirectResponse
    {
        abort_unless($this->boleh($request, $pegawai), 403);

        $this->wajah->cabut($pegawai, $request->user());

        return back()->with('sukses', "Pendaftaran wajah {$pegawai->nama} berhasil dicabut.");
    }

    /**
     * Sajikan foto referensi lewat route terautentikasi.
     *
     * Foto tidak pernah diletakkan pada disk publik: NFR-04 melarang berkas
     * foto diakses tanpa autentikasi.
     */
    public function show(Request $request, Pegawai $pegawai): StreamedResponse
    {
        abort_unless($this->boleh($request, $pegawai), 403);
        abort_if($pegawai->foto_referensi_path === null, 404);

        $disk = Storage::disk(FotoReferensiWajahService::DISK);

        abort_unless($disk->exists($pegawai->foto_referensi_path), 404);

        return $disk->response(
            $pegawai->foto_referensi_path,
            headers: ['Cache-Control' => 'private, max-age=300'],
        );
    }

    /**
     * Admin UPT hanya boleh menyentuh pegawai unitnya sendiri beserta
     * seksi/subbag di bawahnya (SRS §6).
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
