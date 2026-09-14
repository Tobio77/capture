<?php

namespace App\Services\Laporan;

use App\Enums\PeranPengguna;
use App\Enums\StatusRiwayatLaporan;
use App\Jobs\BuatLaporanResmiJob;
use App\Models\RiwayatLaporan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Riwayat Generate Laporan Resmi (FR-LAP-04, revisi antrian).
 *
 * Cakupannya SENGAJA berbeda dari "Unduh Data"/"Generate Laporan" biasa
 * (yang mengikuti cakupan unit kerja peran): di sini yang menentukan
 * kepemilikan adalah SIAPA YANG MEMINTA, bukan unit kerja apa yang
 * dicakup laporannya. Admin UPT hanya melihat dan dapat menghapus
 * riwayatnya sendiri; superadmin — dan hanya superadmin, bukan admin
 * dinas — boleh melihat dan menghapus milik siapa pun, sebab dialah
 * yang bertanggung jawab atas kebersihan penyimpanan berkas laporan
 * secara keseluruhan.
 */
class RiwayatLaporanService
{
    /**
     * @return Collection<int, RiwayatLaporan>
     */
    public function untuk(User $pelaku): Collection
    {
        return $this->kueriCakupan($pelaku)
            ->with(['user', 'unitKerja'])
            ->latest()
            ->limit(50)
            ->get();
    }

    /**
     * Bentuk siap-tampil untuk Inertia — dipanggil saat halaman dimuat
     * MAUPUN dari endpoint polling, sehingga bentuknya harus sama persis
     * di keduanya.
     *
     * @return array<int, array<string, mixed>>
     */
    public function untukLayar(User $pelaku): array
    {
        $superadmin = $pelaku->role === PeranPengguna::Superadmin;

        return $this->untuk($pelaku)->map(fn (RiwayatLaporan $riwayat) => [
            'id' => $riwayat->id,
            'format' => $riwayat->format,
            'periode_label' => $riwayat->dari->translatedFormat('d M Y').' – '.$riwayat->sampai->translatedFormat('d M Y'),
            'unit_kerja' => $riwayat->unitKerja?->nama ?? 'Seluruh unit kerja',
            'status' => $riwayat->status->value,
            'status_label' => $riwayat->status->label(),
            'pesan_galat' => $riwayat->pesan_galat,
            'dibuat_pada' => $riwayat->created_at->translatedFormat('d M Y H:i'),

            // Nama pemohon hanya berarti bagi superadmin, yang melihat milik
            // semua orang — bagi yang lain, seluruh barisnya toh miliknya
            // sendiri.
            'dibuat_oleh' => $superadmin ? ($riwayat->user->nama ?? '—') : null,
        ])->all();
    }

    public function buat(User $pemohon, string $format, Carbon $dari, Carbon $sampai, ?int $unitKerjaId, string $namaBerkas): RiwayatLaporan
    {
        $riwayat = RiwayatLaporan::query()->create([
            'user_id' => $pemohon->id,
            'format' => $format,
            'dari' => $dari,
            'sampai' => $sampai,
            'unit_kerja_id' => $unitKerjaId,
            'status' => StatusRiwayatLaporan::Antre,
            'nama_berkas' => $namaBerkas,
        ]);

        /*
         * `afterResponse()`, bukan antrian sungguhan — lihat catatan lengkap
         * di BuatLaporanResmiJob. Permintaan HTTP tetap dijawab seketika;
         * berkasnya dirakit sesaat sesudahnya pada proses yang sama.
         */
        BuatLaporanResmiJob::dispatch($riwayat->id)->afterResponse();

        return $riwayat;
    }

    public function bolehMelihat(User $pelaku, RiwayatLaporan $riwayat): bool
    {
        return $pelaku->role === PeranPengguna::Superadmin || $riwayat->user_id === $pelaku->id;
    }

    /**
     * Hapus baris riwayat beserta berkasnya bila sudah pernah tersimpan.
     */
    public function hapus(RiwayatLaporan $riwayat): void
    {
        if ($riwayat->path !== null) {
            Storage::disk('local')->delete($riwayat->path);
        }

        $riwayat->delete();
    }

    /**
     * @return Builder<RiwayatLaporan>
     */
    protected function kueriCakupan(User $pelaku)
    {
        $kueri = RiwayatLaporan::query();

        // Hanya superadmin yang melihat milik semua orang (disetujui
        // eksplisit, bukan bawaan "lintas unit" biasa — admin dinas tetap
        // terbatas pada riwayatnya sendiri di sini).
        if ($pelaku->role !== PeranPengguna::Superadmin) {
            $kueri->where('user_id', $pelaku->id);
        }

        return $kueri;
    }
}
