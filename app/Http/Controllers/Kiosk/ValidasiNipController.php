<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\Pegawai;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Validasi NIP saat pegawai men-tap kartu di kiosk (FR-TAP-03).
 *
 * Pencarian dilakukan pada basis data LOKAL, bukan memanggil WORKA:
 * satu tap tidak boleh bergantung pada tersedianya jaringan ke WORKA,
 * dan data pegawai sudah disinkronkan secara terjadwal.
 */
class ValidasiNipController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nip' => ['required', 'string', 'max:20'],
        ]);

        $nip = trim($data['nip']);

        $pegawai = Pegawai::query()
            ->with('unitKerja:id,kode,nama')
            ->where('nip', $nip)
            ->first();

        if ($pegawai === null) {
            return response()->json([
                'success' => false,
                'code' => 'NIP_NOT_FOUND',
                'message' => 'NIP tidak terdaftar dalam sistem.',
            ], 404);
        }

        /*
         * Pegawai nonaktif dijawab gagal, bukan sukses bertanda aktif=false —
         * kiosk memutuskan lanjut atau tidak dari medan `success` saja.
         */
        if (! $pegawai->aktif) {
            return response()->json([
                'success' => false,
                'code' => 'PEGAWAI_TIDAK_AKTIF',
                'message' => 'Pegawai tidak aktif.',
            ], 403);
        }

        $kiosk = $request->kiosk();

        return response()->json([
            'success' => true,
            'data' => [
                'nip' => $pegawai->nip,
                'nama' => $pegawai->nama,
                'jabatan' => $pegawai->jabatan,
                'unit_kerja_kode' => $pegawai->unitKerja?->kode,
                'unit_kerja_nama' => $pegawai->unitKerja?->nama,

                // Unit kerja pegawai berbeda dari titik absen — kiosk memakai
                // ini untuk menandai peserta dari luar unit pada event lintas unit.
                'unit_kerja_sama' => $pegawai->unit_kerja_id === $kiosk?->unit_kerja_id,

                // Foto berasal dari WORKA dan disajikan lewat proxy SI-ABSEN,
                // sehingga token WORKA tidak pernah sampai ke browser kiosk.
                'foto_tersedia' => $pegawai->foto_tersedia_worka,
                'foto_url' => route('kiosk.pegawai.foto', ['nip' => $pegawai->nip]),

                // Penanda milik SI-ABSEN sendiri (S08), terpisah dari foto WORKA.
                'wajah_terdaftar' => $pegawai->wajah_terdaftar,
            ],
        ]);
    }
}
