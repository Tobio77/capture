<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Exceptions\WorkaApiException;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Support\PengaturanRepository;
use App\Support\SinkronResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Sinkronisasi master data pegawai dan unit kerja dari WORKA (FR-PEG-01).
 *
 * Data pegawai bersifat baca-saja di SI-ABSEN (FR-PEG-02): seluruh perubahan
 * berasal dari WORKA, dan yang disimpan di sini hanya cerminan seperlunya
 * untuk mengabsen — ditambah kolom milik SI-ABSEN sendiri
 * (foto_referensi_path, wajah_terdaftar) yang tidak pernah ditimpa.
 */
class SinkronisasiPegawaiService
{
    public const string KUNCI_CHECKSUM = 'worka.checksum_terakhir';

    public const string KUNCI_SINKRON_TERAKHIR = 'worka.sinkron_terakhir_at';

    public const string KUNCI_TOTAL_WORKA = 'worka.total_pegawai_aktif';

    public const string KUNCI_UNIT_SINKRON_TERAKHIR = 'worka.unit_kerja_sinkron_terakhir_at';

    /** Jumlah baris per halaman saat menarik pegawai (batas WORKA: 1000). */
    protected const int PER_HALAMAN = 500;

    public function __construct(
        protected WorkaApiClient $worka,
        protected PengaturanRepository $pengaturan,
        protected LogAktivitasService $log,
    ) {}

    /**
     * Sinkronisasi inkremental — hanya menarik pegawai yang berubah sejak
     * sinkronisasi terakhir, dan melewatkannya sama sekali bila checksum
     * WORKA belum bergerak.
     */
    public function sinkronInkremental(?User $pelaku = null): SinkronResult
    {
        return $this->jalankan(penuh: false, pelaku: $pelaku);
    }

    /**
     * Sinkronisasi penuh — menarik seluruh pegawai aktif dan menonaktifkan
     * pegawai lokal yang sudah tidak ada di WORKA.
     */
    public function sinkronPenuh(?User $pelaku = null): SinkronResult
    {
        return $this->jalankan(penuh: true, pelaku: $pelaku);
    }

    protected function jalankan(bool $penuh, ?User $pelaku): SinkronResult
    {
        $mulai = microtime(true);

        if (! $this->worka->terkonfigurasi()) {
            Log::channel('worka')->warning('Sinkronisasi dilewati: token API WORKA belum dikonfigurasi.');

            return SinkronResult::gagal(
                'Token API WORKA belum dikonfigurasi. Isi melalui menu Setting → Integrasi WORKA.',
                microtime(true) - $mulai,
            );
        }

        try {
            $status = $this->worka->getSyncStatus();

            if (! $penuh && $this->checksumSama($status['checksum'] ?? null)) {
                $this->pengaturan->simpan(self::KUNCI_SINKRON_TERAKHIR, Carbon::now()->toIso8601String());

                return SinkronResult::tidakAdaPerubahan(microtime(true) - $mulai);
            }

            // Unit kerja lebih dulu: pegawai memiliki FK ke unit_kerja.
            $unitDiperbarui = $this->sinkronUnitKerja();

            $hasil = $penuh ? $this->tarikSemua() : $this->tarikPerubahan();

            $dinonaktifkan = $penuh
                ? $this->nonaktifkanYangTidakAdaDiWorka($hasil['nip_ditemukan'])
                : 0;

            $this->pengaturan->simpanBanyak([
                self::KUNCI_CHECKSUM => $status['checksum'] ?? null,
                self::KUNCI_SINKRON_TERAKHIR => Carbon::now()->toIso8601String(),
                self::KUNCI_TOTAL_WORKA => (string) ($status['total_pegawai_aktif'] ?? 0),
            ]);

            $ringkasan = SinkronResult::sukses(
                ditambahkan: $hasil['ditambahkan'],
                diperbarui: $hasil['diperbarui'],
                dinonaktifkan: $dinonaktifkan,
                unitKerjaDiperbarui: $unitDiperbarui,
                durasiDetik: microtime(true) - $mulai,
            );

            $this->log->catat(
                AksiLog::Ubah,
                sprintf(
                    'Sinkronisasi pegawai %s dari WORKA: %d ditambahkan, %d diperbarui, %d dinonaktifkan.',
                    $penuh ? 'penuh' : 'inkremental',
                    $ringkasan->ditambahkan,
                    $ringkasan->diperbarui,
                    $ringkasan->dinonaktifkan,
                ),
                user: $pelaku,
            );

            return $ringkasan;
        } catch (WorkaApiException $e) {
            return SinkronResult::gagal($e->pesanUntukAdmin(), microtime(true) - $mulai);
        }
    }

    /**
     * Sinkronkan daftar unit kerja dari WORKA.
     *
     * Nama dan kode mengikuti WORKA. Kolom `aktif` di SI-ABSEN adalah penanda
     * keikutsertaan yang diatur admin (FR-UNIT-01), jadi hanya diturunkan
     * paksa ketika WORKA menyatakan unitnya sudah tidak aktif — unit yang
     * aktif di WORKA tidak dipaksa aktif kembali di sini.
     *
     * @return int jumlah unit kerja yang dibuat atau diperbarui
     */
    public function sinkronUnitKerja(): int
    {
        $daftar = $this->worka->getUnitKerja()['data'];
        $tersentuh = 0;

        foreach ($daftar as $unit) {
            $kode = $unit['kode'] ?? null;

            if (! is_string($kode) || $kode === '') {
                continue;
            }

            $lokal = UnitKerja::query()->where('kode', $kode)->first();
            $aktifDiWorka = (bool) ($unit['aktif'] ?? true);

            if ($lokal === null) {
                UnitKerja::query()->create([
                    'kode' => $kode,
                    'nama' => $unit['nama'] ?? $kode,
                    'aktif' => $aktifDiWorka,
                ]);
                $tersentuh++;

                continue;
            }

            $perubahan = ['nama' => $unit['nama'] ?? $lokal->nama];

            if (! $aktifDiWorka) {
                $perubahan['aktif'] = false;
            }

            $lokal->fill($perubahan);

            if ($lokal->isDirty()) {
                $lokal->save();
                $tersentuh++;
            }
        }

        $this->pengaturan->simpan(self::KUNCI_UNIT_SINKRON_TERAKHIR, Carbon::now()->toIso8601String());

        return $tersentuh;
    }

    /**
     * @return array{ditambahkan: int, diperbarui: int, nip_ditemukan: array<int, string>}
     */
    protected function tarikSemua(): array
    {
        return $this->tarik([]);
    }

    /**
     * @return array{ditambahkan: int, diperbarui: int, nip_ditemukan: array<int, string>}
     */
    protected function tarikPerubahan(): array
    {
        $sejak = $this->pengaturan->ambil(self::KUNCI_SINKRON_TERAKHIR);

        // Tanpa penanda waktu sebelumnya, inkremental sama saja dengan penuh.
        return $this->tarik($sejak ? ['updated_after' => $sejak] : []);
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array{ditambahkan: int, diperbarui: int, nip_ditemukan: array<int, string>}
     */
    protected function tarik(array $filter): array
    {
        $unitKerja = UnitKerja::query()->pluck('id', 'kode');
        $ditambahkan = 0;
        $diperbarui = 0;
        $nipDitemukan = [];
        $halaman = 1;

        do {
            $jawaban = $this->worka->getPegawai($filter + [
                'per_page' => self::PER_HALAMAN,
                'page' => $halaman,
            ]);

            foreach ($jawaban['data'] as $baris) {
                $nip = $baris['nip'] ?? null;
                $kodeUnit = $baris['unit_kerja']['kode'] ?? null;

                if (! is_string($nip) || $nip === '' || ! isset($unitKerja[$kodeUnit])) {
                    // Unit kerja yang belum dikenal dilewati, bukan digagalkan:
                    // sinkronisasi unit berikutnya akan menariknya masuk.
                    Log::channel('worka')->warning('Baris pegawai dilewati saat sinkronisasi.', [
                        'nip' => $nip,
                        'unit_kerja_kode' => $kodeUnit,
                    ]);

                    continue;
                }

                $nipDitemukan[] = $nip;

                $atribut = [
                    'nama' => $baris['nama'] ?? '',
                    'unit_kerja_id' => $unitKerja[$kodeUnit],
                    'jabatan' => $baris['jabatan'] ?? null,
                    'aktif' => (bool) ($baris['status_aktif'] ?? true),
                    'foto_tersedia_worka' => (bool) ($baris['foto_tersedia'] ?? false),
                    'sumber_sinkron_terakhir' => Carbon::now(),
                ];

                $pegawai = Pegawai::query()->where('nip', $nip)->first();

                if ($pegawai === null) {
                    Pegawai::query()->create(['nip' => $nip] + $atribut);
                    $ditambahkan++;

                    continue;
                }

                $pegawai->fill($atribut);

                // sumber_sinkron_terakhir selalu berubah, jadi keterubahan
                // dinilai dari medan yang benar-benar berasal dari WORKA.
                $berubah = collect($pegawai->getDirty())
                    ->except('sumber_sinkron_terakhir')
                    ->isNotEmpty();

                $pegawai->save();

                if ($berubah) {
                    $diperbarui++;
                }
            }

            $halamanTerakhir = (int) ($jawaban['meta']['last_page'] ?? 1);
            $halaman++;
        } while ($halaman <= $halamanTerakhir);

        return [
            'ditambahkan' => $ditambahkan,
            'diperbarui' => $diperbarui,
            'nip_ditemukan' => $nipDitemukan,
        ];
    }

    /**
     * Pegawai lokal yang tidak lagi muncul pada daftar aktif WORKA
     * dinonaktifkan, bukan dihapus — riwayat absensinya menautnya.
     *
     * @param  array<int, string>  $nipDitemukan
     */
    protected function nonaktifkanYangTidakAdaDiWorka(array $nipDitemukan): int
    {
        if ($nipDitemukan === []) {
            // Jawaban kosong lebih mungkin berarti gangguan daripada seluruh
            // pegawai berhenti serentak; jangan menonaktifkan apa pun.
            return 0;
        }

        return Pegawai::query()
            ->where('aktif', true)
            ->whereNotIn('nip', $nipDitemukan)
            ->update(['aktif' => false]);
    }

    protected function checksumSama(?string $checksum): bool
    {
        return $checksum !== null
            && $checksum === $this->pengaturan->ambil(self::KUNCI_CHECKSUM);
    }

    /**
     * Ringkasan status sinkronisasi untuk panel admin.
     *
     * $cekKoneksi sengaja dapat dimatikan: memuat halaman Kelola Pegawai
     * tidak boleh menunggu satu perjalanan HTTP ke WORKA. Halaman menarik
     * status koneksinya sendiri lewat endpoint JSON setelah tabel tampil.
     *
     * @return array<string, mixed>
     */
    public function statusSinkron(bool $cekKoneksi = true): array
    {
        return [
            'terkonfigurasi' => $this->worka->terkonfigurasi(),
            'terhubung' => $cekKoneksi && $this->worka->terkonfigurasi()
                ? $this->worka->cekKoneksi()
                : null,
            'api_url' => $this->worka->baseUrl(),
            'sinkron_terakhir_at' => $this->pengaturan->ambil(self::KUNCI_SINKRON_TERAKHIR),
            'total_pegawai_worka' => (int) ($this->pengaturan->ambil(self::KUNCI_TOTAL_WORKA) ?? 0),
            'total_pegawai_lokal' => Pegawai::query()->where('aktif', true)->count(),
        ];
    }
}
