<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Enums\PeranPengguna;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Support\Collection;
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

    public function __construct(protected LogAktivitasService $log) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function daftar(): Collection
    {
        return User::query()
            ->with('unitKerja:id,kode,nama')
            ->orderBy('nama')
            ->get()
            ->map(fn (User $pengguna) => [
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

        // Sesi yang sedang berjalan ikut gugur, supaya akun yang disalahgunakan
        // benar-benar terputus begitu sandinya diganti.
        $pengguna->setRememberToken(Str::random(60));
        $pengguna->save();

        $this->log->catat(
            AksiLog::Ubah,
            "Menerbitkan kata sandi baru untuk {$pengguna->nama} ({$pengguna->email}).",
            user: $pelaku,
            subjek: $pengguna,
        );

        return $sandi;
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
