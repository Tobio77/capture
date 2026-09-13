<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Models\HariLibur;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pengelolaan hari libur bertanggal (FR-SET-08).
 *
 * Dipisah dari controller supaya `tambah()` dapat dipakai dua jalan sekaligus
 * — satu tanggal lewat formulir biasa, atau banyak tanggal lewat impor massal
 * ({@see self::impor()}) — tanpa menyalin ulang aturan keunikan dan
 * pencatatan audit trail-nya.
 */
class HariLiburService
{
    public function __construct(protected LogAktivitasService $log) {}

    /**
     * Apakah pengguna berwenang memasang/mencabut hari libur pada cakupan ini.
     *
     * Libur nasional (`$unitKerjaId === null`) hanya boleh disentuh peran
     * lintas unit: satu orang yang keliru menandai hari kerja sebagai libur
     * nasional akan menandai seluruh absensi hari itu di seluruh provinsi.
     * Admin UPT terkunci pada unitnya sendiri beserta turunannya.
     */
    public function bolehMenyentuh(User $pengguna, ?int $unitKerjaId): bool
    {
        if ($pengguna->lintasUnit()) {
            return true;
        }

        return $unitKerjaId !== null
            && in_array($unitKerjaId, UnitKerja::idsDenganTurunan($pengguna->unit_kerja_id), true);
    }

    /**
     * Tambahkan satu hari libur.
     *
     * @throws ValidationException bila tanggal ini sudah terdaftar untuk cakupan yang sama
     */
    public function tambah(Carbon $tanggal, string $keterangan, ?int $unitKerjaId, User $pelaku): HariLibur
    {
        $sudahAda = HariLibur::query()
            ->whereDate('tanggal', $tanggal)
            ->where('unit_kerja_id', $unitKerjaId)
            ->exists();

        if ($sudahAda) {
            throw ValidationException::withMessages([
                'tanggal' => 'Tanggal itu sudah terdaftar untuk cakupan yang sama.',
            ]);
        }

        $libur = HariLibur::query()->create([
            'tanggal' => $tanggal,
            'keterangan' => $keterangan,
            'unit_kerja_id' => $unitKerjaId,
            'dibuat_oleh' => $pelaku->id,
        ]);

        $this->log->catat(
            AksiLog::Buat,
            sprintf(
                'Menambah hari libur %s — %s (%s).',
                $tanggal->toDateString(),
                $libur->keterangan,
                $unitKerjaId === null ? 'seluruh unit' : $this->namaUnit($unitKerjaId),
            ),
            user: $pelaku,
            subjek: $libur,
        );

        return $libur;
    }

    /**
     * Impor banyak tanggal sekaligus, satu cakupan yang sama untuk semuanya.
     *
     * Setiap baris diproses dan dilaporkan hasilnya sendiri-sendiri — satu
     * baris yang salah ketik tidak menggagalkan baris lain di sekitarnya, dan
     * tidak ada baris yang dilewati tanpa alasan yang dapat dibaca. Baris yang
     * tanggalnya kebetulan sama dengan baris lain dalam tempelan yang sama
     * ikut tertangkap sebagai duplikat: baris kedua menemukan baris pertama
     * sudah tersimpan.
     *
     * @param  array<int, array{baris: int, tanggal: string, keterangan: string}>  $baris  sudah dipecah per baris asli, belum divalidasi
     * @return array{ditambahkan: array<int, string>, dilewati: array<int, array{baris: int, alasan: string}>}
     */
    public function impor(array $baris, ?int $unitKerjaId, User $pelaku): array
    {
        $ditambahkan = [];
        $dilewati = [];

        foreach ($baris as $satu) {
            $tanggal = $this->uraikanTanggal($satu['tanggal']);

            if ($tanggal === null) {
                $dilewati[] = [
                    'baris' => $satu['baris'],
                    'alasan' => "Tanggal \"{$satu['tanggal']}\" tidak dikenali. Pakai format YYYY-MM-DD.",
                ];

                continue;
            }

            $keterangan = trim($satu['keterangan']);

            if ($keterangan === '') {
                $dilewati[] = ['baris' => $satu['baris'], 'alasan' => 'Keterangan tidak boleh kosong.'];

                continue;
            }

            if (mb_strlen($keterangan) > 150) {
                $dilewati[] = ['baris' => $satu['baris'], 'alasan' => 'Keterangan lebih dari 150 karakter.'];

                continue;
            }

            try {
                $libur = $this->tambah($tanggal, $keterangan, $unitKerjaId, $pelaku);
                $ditambahkan[] = "{$tanggal->toDateString()} — {$libur->keterangan}";
            } catch (ValidationException) {
                $dilewati[] = [
                    'baris' => $satu['baris'],
                    'alasan' => "Tanggal {$tanggal->toDateString()} sudah terdaftar untuk cakupan ini.",
                ];
            }
        }

        return ['ditambahkan' => $ditambahkan, 'dilewati' => $dilewati];
    }

    public function hapus(HariLibur $hariLibur, User $pelaku): void
    {
        $keterangan = "{$hariLibur->tanggal->toDateString()} — {$hariLibur->keterangan}";

        $hariLibur->delete();

        $this->log->catat(
            AksiLog::Hapus,
            "Menghapus hari libur {$keterangan}.",
            user: $pelaku,
        );
    }

    /**
     * "2026-01-01" → Carbon, atau null bila bentuknya bukan itu persis.
     *
     * Sengaja ketat, bukan `Carbon::parse()` yang menebak-nebak format:
     * tanggal ambigu semacam "01/02/2026" akan salah diam-diam bagi salah satu
     * pembacanya. Bentuk overflow seperti "2026-02-30" juga ditolak — Carbon
     * menerimanya begitu saja dan diam-diam menjadikannya 2 Maret.
     */
    protected function uraikanTanggal(string $mentah): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $mentah)) {
            return null;
        }

        try {
            $tanggal = Carbon::createFromFormat('Y-m-d', $mentah)?->startOfDay();
        } catch (Throwable) {
            return null;
        }

        return $tanggal?->format('Y-m-d') === $mentah ? $tanggal : null;
    }

    protected function namaUnit(int $unitKerjaId): string
    {
        return UnitKerja::query()->whereKey($unitKerjaId)->value('nama') ?? 'unit tidak dikenal';
    }
}
