<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Enums\PeranPengguna;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Pengelolaan akun admin (FR-USR-01).
 *
 * Akun tidak pernah dihapus, hanya dinonaktifkan — audit trail menautnya, dan
 * jejak siapa mengubah apa harus tetap dapat ditelusuri.
 */
class PenggunaService
{
    /** Panjang kata sandi sementara yang diterbitkan sistem. */
    public const int PANJANG_SANDI = 12;

    /** Jumlah akun per halaman pada daftar Kelola User. */
    public const int PER_HALAMAN = 15;

    public function __construct(protected LogAktivitasService $log) {}

    /**
     * @param  array<string, mixed>  $filter  cari, role, status
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function daftar(array $filter = []): LengthAwarePaginator
    {
        return User::query()
            ->with('unitKerja:id,kode,nama')
            ->when(
                filled($filter['cari'] ?? null),
                fn ($q) => $q->where(function ($cari) use ($filter) {
                    $cari->where('nama', 'like', '%'.$filter['cari'].'%')
                        ->orWhere('email', 'like', '%'.$filter['cari'].'%');
                }),
            )
            ->when(
                filled($filter['role'] ?? null),
                fn ($q) => $q->where('role', $filter['role']),
            )
            ->when(
                ($filter['status'] ?? '') === 'aktif',
                fn ($q) => $q->where('aktif', true),
            )
            ->when(
                ($filter['status'] ?? '') === 'nonaktif',
                fn ($q) => $q->where('aktif', false),
            )
            ->orderBy('nama')
            ->paginate(self::PER_HALAMAN)
            ->withQueryString()
            ->through(fn (User $pengguna) => [
                'id' => $pengguna->id,
                'nama' => $pengguna->nama,
                'email' => $pengguna->email,
                'role' => $pengguna->role->value,
                'role_label' => $pengguna->role->label(),
                'unit_kerja' => $pengguna->unitKerja?->only(['id', 'kode', 'nama']),
                'aktif' => $pengguna->aktif,
            ]);
    }

    /**
     * Buat akun admin baru beserta kata sandi sementaranya.
     *
     * @param  array<string, mixed>  $data
     * @return array{pengguna: User, sandi: string}
     */
    public function buat(array $data, User $pelaku): array
    {
        $sandi = $this->sandiAcak();

        $pengguna = User::create([
            'nama' => $data['nama'],
            'email' => $data['email'],
            'password' => Hash::make($sandi),
            'role' => $data['role'],
            'unit_kerja_id' => $this->unitUntukPeran($data),
            'aktif' => true,
        ]);

        $this->log->catat(
            AksiLog::Buat,
            "Membuat akun {$pengguna->role->label()} {$pengguna->nama} ({$pengguna->email}).",
            user: $pelaku,
            subjek: $pengguna,
        );

        return ['pengguna' => $pengguna, 'sandi' => $sandi];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function perbarui(User $pengguna, array $data, User $pelaku): User
    {
        $sebelum = "{$pengguna->nama} ({$pengguna->role->label()})";

        $pengguna->update([
            'nama' => $data['nama'],
            'email' => $data['email'],
            'role' => $data['role'],
            'unit_kerja_id' => $this->unitUntukPeran($data),
        ]);

        $this->log->catat(
            AksiLog::Ubah,
            "Mengubah akun {$sebelum} menjadi {$pengguna->nama} ({$pengguna->role->label()}).",
            user: $pelaku,
            subjek: $pengguna,
        );

        return $pengguna;
    }

    /**
     * Aktifkan atau nonaktifkan akun. Akun yang dinonaktifkan langsung
     * kehilangan akses lewat middleware `pengguna.aktif`.
     */
    public function ubahStatus(User $pengguna, bool $aktif, User $pelaku): User
    {
        $pengguna->update(['aktif' => $aktif]);

        // Akun yang dinonaktifkan diputus sesinya di tempat, tidak menunggu
        // permintaan berikutnya melewati middleware `pengguna.aktif`.
        if (! $aktif) {
            $this->putusSesi($pengguna);
        }

        $this->log->catat(
            AksiLog::Ubah,
            ($aktif ? 'Mengaktifkan' : 'Menonaktifkan')." akun {$pengguna->nama} ({$pengguna->email}).",
            user: $pelaku,
            subjek: $pengguna,
        );

        return $pengguna;
    }

    /**
     * Terbitkan kata sandi sementara baru.
     *
     * Sandi lama tidak pernah dapat dibaca siapa pun — yang tersimpan hanya
     * hash-nya — sehingga "reset" berarti menerbitkan yang baru, bukan
     * menampilkan yang lama.
     */
    public function resetSandi(User $pengguna, User $pelaku): string
    {
        $sandi = $this->sandiAcak();

        $pengguna->update(['password' => Hash::make($sandi)]);

        $this->putusSesi($pengguna);

        $this->log->catat(
            AksiLog::Ubah,
            "Menerbitkan kata sandi baru untuk {$pengguna->nama} ({$pengguna->email}).",
            user: $pelaku,
            subjek: $pengguna,
        );

        return $sandi;
    }

    /**
     * Putuskan seluruh sesi milik sebuah akun (NFR-03).
     *
     * Mengganti kata sandi saja tidak cukup: cookie sesi yang sudah terbit
     * tetap sah sampai kedaluwarsa, sehingga akun yang disalahgunakan masih
     * hidup di peramban penyalahgunanya. Karena sesi disimpan di basis data,
     * barisnya dapat dihapus langsung.
     *
     * Remember token ikut diputar agar cookie "ingat saya" tidak dapat
     * memulihkan sesi yang baru saja diputus.
     */
    protected function putusSesi(User $pengguna): void
    {
        $pengguna->setRememberToken(Str::random(60));
        $pengguna->save();

        // Driver sesi selain database tidak menyimpan barisnya di sini;
        // pemutusan lewat remember token tetap berlaku.
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $pengguna->id)
            ->delete();
    }

    /**
     * Unit kerja yang boleh dipilih sebagai cakupan Admin UPT.
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

    /**
     * Cakupan unit hanya bermakna untuk Admin UPT; peran lintas unit selalu
     * menyimpan null agar tidak ada cakupan bayangan yang menyesatkan.
     *
     * @param  array<string, mixed>  $data
     */
    protected function unitUntukPeran(array $data): ?int
    {
        return PeranPengguna::from($data['role']) === PeranPengguna::AdminUpt
            ? (int) $data['unit_kerja_id']
            : null;
    }

    /**
     * Kata sandi sementara yang mudah dibacakan lewat telepon: tanpa karakter
     * yang mudah tertukar (0/O, 1/l/I).
     */
    protected function sandiAcak(): string
    {
        $abjad = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $sandi = '';

        for ($i = 0; $i < self::PANJANG_SANDI; $i++) {
            $sandi .= $abjad[random_int(0, strlen($abjad) - 1)];
        }

        return $sandi;
    }
}
