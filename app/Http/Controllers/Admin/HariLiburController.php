<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HariLibur;
use App\Services\HariLiburService;
use Illuminate\Http\JsonResponse;
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
 *
 * Rute ini sendiri sudah dipagari `peran:superadmin,admin_dinas` (Admin UPT
 * tidak pernah menyentuh Setting Absen sama sekali — lihat
 * `HariLiburTest::admin_upt_tidak_dapat_menyentuh_kalender_sama_sekali`), jadi
 * pemeriksaan `bolehMenyentuh()` di bawah ini adalah lapis kedua: berjaga
 * seandainya kelak rute ini dibuka lebih longgar, bukan jalur yang dipakai
 * sehari-hari.
 */
class HariLiburController extends Controller
{
    public function __construct(protected HariLiburService $hariLibur) {}

    public function store(Request $request): RedirectResponse
    {
        $pengguna = $request->user();

        $data = $request->validate([
            'tanggal' => ['required', 'date'],
            'keterangan' => ['required', 'string', 'max:150'],
            'unit_kerja_id' => ['nullable', 'integer', Rule::exists('unit_kerja', 'id')],
        ], attributes: [
            'tanggal' => 'tanggal',
            'keterangan' => 'keterangan',
            'unit_kerja_id' => 'unit kerja',
        ]);

        $unitId = $data['unit_kerja_id'] ?? null;

        /*
         * Diperiksa di sini, bukan hanya di layar: layar hanya menyembunyikan
         * pilihannya, dan yang disembunyikan masih dapat dikirim.
         */
        abort_unless(
            $this->hariLibur->bolehMenyentuh($pengguna, $unitId),
            403,
            'Hari libur nasional hanya dapat dipasang admin lintas unit.',
        );

        // ValidationException dari tambah() ditangani otomatis oleh Laravel,
        // kembali sebagai redirect-back dengan error bag 'tanggal' — sama
        // seperti sebelum diekstrak ke HariLiburService.
        $this->hariLibur->tambah(
            Carbon::parse($data['tanggal'])->startOfDay(),
            $data['keterangan'],
            $unitId,
            $pengguna,
        );

        return back()->with('sukses', 'Hari libur berhasil ditambahkan.');
    }

    /**
     * Impor banyak tanggal sekaligus dari satu tempelan teks (FR-SET-08).
     *
     * Dijawab sebagai JSON, bukan redirect Inertia: hasilnya berupa laporan
     * per baris yang perlu tetap terlihat di modal pengirimnya, bukan flash
     * message satu baris yang hilang begitu halaman berpindah.
     */
    public function impor(Request $request): JsonResponse
    {
        $pengguna = $request->user();

        $data = $request->validate([
            'teks' => ['required', 'string'],
            'unit_kerja_id' => ['nullable', 'integer', Rule::exists('unit_kerja', 'id')],
        ]);

        $unitId = $data['unit_kerja_id'] ?? null;

        abort_unless(
            $this->hariLibur->bolehMenyentuh($pengguna, $unitId),
            403,
            'Hari libur nasional hanya dapat dipasang admin lintas unit.',
        );

        $hasil = $this->hariLibur->impor($this->uraikanBaris($data['teks']), $unitId, $pengguna);

        return response()->json($hasil);
    }

    public function destroy(Request $request, HariLibur $hariLibur): RedirectResponse
    {
        $pengguna = $request->user();

        abort_unless(
            $this->hariLibur->bolehMenyentuh($pengguna, $hariLibur->unit_kerja_id),
            403,
        );

        $this->hariLibur->hapus($hariLibur, $pengguna);

        return back()->with('sukses', 'Hari libur berhasil dihapus.');
    }

    /**
     * Pecah tempelan teks menjadi baris {baris, tanggal, keterangan}.
     *
     * Nomor barisnya mengikuti baris ASLI dalam tempelan (bukan nomor urut
     * baris yang tidak kosong), supaya admin dapat menemukannya kembali di
     * textarea saat laporan menyebut "Baris 8".
     *
     * @return array<int, array{baris: int, tanggal: string, keterangan: string}>
     */
    protected function uraikanBaris(string $teks): array
    {
        $hasil = [];

        foreach (preg_split('/\r\n|\r|\n/', $teks) as $nomor => $baris) {
            $baris = trim($baris);

            if ($baris === '') {
                continue;
            }

            [$tanggal, $keterangan] = array_pad(explode(';', $baris, 2), 2, '');

            $hasil[] = [
                'baris' => $nomor + 1,
                'tanggal' => trim($tanggal),
                'keterangan' => trim($keterangan),
            ];
        }

        return $hasil;
    }
}
