<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Models\Kiosk;
use App\Models\LogAktivitas;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Pengelolaan perangkat absen dari panel admin (FR-USR-02, FR-USR-03).
 *
 * Perangkat tidak pernah dihapus, hanya dinonaktifkan: riwayat absensi dan
 * jejak aktivasinya menautnya (NFR-09).
 */
class PerangkatAbsenService
{
    /** Panjang riwayat aktivasi yang ditampilkan per perangkat. */
    public const int BATAS_RIWAYAT = 10;

    /** Jumlah perangkat per halaman pada daftar Perangkat Absen. */
    public const int PER_HALAMAN = 15;

    public function __construct(
        protected KioskService $kiosk,
        protected LogAktivitasService $log,
    ) {}

    /**
     * @param  array<int, int>|null  $cakupan
     * @param  array<string, mixed>  $filter  cari, unit_kerja_id, status
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function daftar(?array $cakupan = null, array $filter = []): LengthAwarePaginator
    {
        return Kiosk::query()
            ->with('unitKerja:id,kode,nama')
            ->when($cakupan !== null, fn ($q) => $q->whereIn('unit_kerja_id', $cakupan))
            ->when(
                filled($filter['cari'] ?? null),
                fn ($q) => $q->where('nama_titik', 'like', '%'.$filter['cari'].'%'),
            )
            ->when(
                filled($filter['unit_kerja_id'] ?? null),
                fn ($q) => $q->whereIn(
                    'unit_kerja_id',
                    UnitKerja::idsDenganTurunan((int) $filter['unit_kerja_id']),
                ),
            )
            ->when(
                ($filter['status'] ?? '') === 'terpasang',
                fn ($q) => $q->whereNotNull('device_token'),
            )
            ->when(
                ($filter['status'] ?? '') === 'belum',
                fn ($q) => $q->whereNull('device_token'),
            )
            ->when(
                ($filter['status'] ?? '') === 'nonaktif',
                fn ($q) => $q->where('aktif', false),
            )
            ->orderBy('nama_titik')
            ->paginate(self::PER_HALAMAN)
            ->withQueryString()
            ->through(fn (Kiosk $perangkat) => [
                'id' => $perangkat->id,
                'nama_titik' => $perangkat->nama_titik,

                // FR-SET-06: perangkat ad-hoc tidak pernah ditinjau admin,
                // sehingga asal-usulnya harus terbaca pada daftar.
                'sumber' => $perangkat->sumber->value,
                'sumber_label' => $perangkat->sumber->label(),
                'unit_kerja' => $perangkat->unitKerja?->only(['id', 'kode', 'nama']),
                'aktif' => $perangkat->aktif,

                /*
                 * "Terpasang" berarti perangkat sudah memegang device token —
                 * berbeda dari "aktif", yang menyatakan apakah akunnya masih
                 * diizinkan admin.
                 */
                'terpasang' => $perangkat->device_token !== null,
                'diaktifkan_pada' => $perangkat->diaktifkan_pada?->toIso8601String(),

                // FR-USR-03
                'ip_terakhir' => $perangkat->ip_terakhir,
                'login_terakhir_at' => $perangkat->login_terakhir_at?->toIso8601String(),

                'kode_aktivasi_berlaku' => $perangkat->kode_aktivasi !== null
                    && $perangkat->kode_aktivasi_kedaluwarsa_at?->isFuture() === true,
                'kode_aktivasi_kedaluwarsa_at' => $perangkat->kode_aktivasi_kedaluwarsa_at?->toIso8601String(),
            ]);
    }

    /**
     * Daftarkan perangkat baru dan langsung terbitkan kode aktivasinya —
     * perangkat tanpa kode tidak berguna bagi petugas di lokasi.
     *
     * @param  array<string, mixed>  $data
     * @return array{perangkat: Kiosk, kode: string}
     */
    public function daftarkan(array $data, User $pelaku): array
    {
        $perangkat = Kiosk::create([
            'nama_titik' => $data['nama_titik'],
            'unit_kerja_id' => (int) $data['unit_kerja_id'],
            'aktif' => true,
        ]);

        $kode = $this->kiosk->terbitkanKodeAktivasi($perangkat);

        $this->log->catat(
            AksiLog::Buat,
            "Mendaftarkan perangkat absen {$perangkat->nama_titik}.",
            user: $pelaku,
            subjek: $perangkat,
        );

        return ['perangkat' => $perangkat, 'kode' => $kode];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function perbarui(Kiosk $perangkat, array $data, User $pelaku): Kiosk
    {
        $sebelum = $perangkat->nama_titik;

        $perangkat->update([
            'nama_titik' => $data['nama_titik'],
            'unit_kerja_id' => (int) $data['unit_kerja_id'],
        ]);

        $this->log->catat(
            AksiLog::Ubah,
            "Mengubah perangkat absen {$sebelum} menjadi {$perangkat->nama_titik}.",
            user: $pelaku,
            subjek: $perangkat,
        );

        return $perangkat;
    }

    /**
     * Terbitkan kode aktivasi baru, misalnya karena kode lama telanjur
     * kedaluwarsa sebelum petugas sempat memakainya.
     */
    public function terbitkanKode(Kiosk $perangkat, User $pelaku): string
    {
        $kode = $this->kiosk->terbitkanKodeAktivasi($perangkat);

        $this->log->catat(
            AksiLog::Ubah,
            "Menerbitkan kode aktivasi baru untuk {$perangkat->nama_titik}.",
            user: $pelaku,
            subjek: $perangkat,
        );

        return $kode;
    }

    /**
     * Cabut device token dari sisi admin (FR-USR-02).
     *
     * Dipakai ketika perangkat hilang atau dipindahkan: token yang dicabut
     * langsung kehilangan akses pada permintaan berikutnya, tanpa perlu
     * menyentuh perangkatnya.
     */
    public function cabutToken(Kiosk $perangkat, User $pelaku): void
    {
        // Pencabutannya sendiri sudah dicatat KioskService beserta pelakunya;
        // menambah catatan kedua di sini hanya membuat audit trail berisik.
        $this->kiosk->lepas($perangkat, $pelaku);
    }

    /**
     * Nonaktifkan perangkat yang tidak lagi dipakai (FR-USR-02).
     *
     * Menonaktifkan sekaligus mencabut tokennya: akun yang dinyatakan tidak
     * dipakai tidak boleh tetap melayani tap sampai seseorang ingat
     * mencabutnya secara terpisah.
     */
    public function ubahStatus(Kiosk $perangkat, bool $aktif, User $pelaku): Kiosk
    {
        if (! $aktif) {
            $this->kiosk->lepas($perangkat, $pelaku);
        }

        $perangkat->update(['aktif' => $aktif]);

        $this->log->catat(
            AksiLog::Ubah,
            ($aktif ? 'Mengaktifkan' : 'Menonaktifkan')." perangkat absen {$perangkat->nama_titik}.",
            user: $pelaku,
            subjek: $perangkat,
        );

        return $perangkat;
    }

    /**
     * Riwayat aktivasi dan pencabutan sebuah perangkat (FR-USR-03).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function riwayat(Kiosk $perangkat): Collection
    {
        return LogAktivitas::query()
            ->with('user:id,nama')
            ->where('subjek_type', Kiosk::class)
            ->where('subjek_id', $perangkat->id)
            ->orWhere(fn ($q) => $q->where('kiosk_id', $perangkat->id))
            ->orderByDesc('created_at')
            ->limit(self::BATAS_RIWAYAT)
            ->get()
            ->map(fn (LogAktivitas $log) => [
                'id' => $log->id,
                'aksi' => $log->aksi->label(),
                'deskripsi' => $log->deskripsi,
                'ip' => $log->ip_address,
                'oleh' => $log->user?->nama,
                'waktu' => $log->created_at->toIso8601String(),
            ]);
    }

    /**
     * Unit kerja yang boleh menampung perangkat absen.
     *
     * @return Collection<int, UnitKerja>
     */
    public function unitKerjaTersedia(): Collection
    {
        return UnitKerja::query()
            ->levelTeratas()
            ->aktif()
            ->orderBy('nama')
            ->get(['id', 'kode', 'nama']);
    }
}
