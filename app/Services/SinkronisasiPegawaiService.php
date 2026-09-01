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
     * Berjalan dua tahap karena induk sebuah unit bisa saja belum tercatat
     * ketika unit anaknya diproses — WORKA tidak menjamin urutan induk lebih
     * dulu. Tahap pertama menyimpan seluruh unit tanpa menyentuh induk_id,
     * tahap kedua baru menautkan induk berdasarkan pencocokan kode setelah
     * semua unit dipastikan ada.
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

        [$tersentuh, $kodeInduk] = $this->simpanUnitKerja($daftar);
        $tersentuh = $this->tautkanInduk($kodeInduk, $tersentuh);
        $tersentuh = $this->tautkanUnitLokal(array_keys($kodeInduk), $tersentuh);

        $this->pengaturan->simpan(self::KUNCI_UNIT_SINKRON_TERAKHIR, Carbon::now()->toIso8601String());

        return count($tersentuh);
    }

    /**
     * Tahap pertama: buat atau perbarui setiap unit kerja, tanpa induk.
     *
     * @param  array<int, array<string, mixed>>  $daftar
     * @return array{0: array<string, true>, 1: array<string, string|null>} kode unit yang tersentuh, dan kode induk per kode unit
     */
    protected function simpanUnitKerja(array $daftar): array
    {
        $tersentuh = [];
        $kodeInduk = [];

        foreach ($daftar as $unit) {
            $kode = $unit['kode'] ?? null;

            if (! is_string($kode) || $kode === '') {
                continue;
            }

            $kodeInduk[$kode] = $this->kodeInduk($unit);

            $lokal = UnitKerja::query()->where('kode', $kode)->first();
            $aktifDiWorka = (bool) ($unit['aktif'] ?? true);

            if ($lokal === null) {
                UnitKerja::query()->create([
                    'kode' => $kode,
                    'nama' => $unit['nama'] ?? $kode,
                    'aktif' => $aktifDiWorka,
                ]);
                $tersentuh[$kode] = true;

                continue;
            }

            $perubahan = ['nama' => $unit['nama'] ?? $lokal->nama];

            if (! $aktifDiWorka) {
                $perubahan['aktif'] = false;
            }

            $lokal->fill($perubahan);

            if ($lokal->isDirty()) {
                $lokal->save();
                $tersentuh[$kode] = true;
            }
        }

        return [$tersentuh, $kodeInduk];
    }

    /**
     * Tahap kedua: tautkan induk_id berdasarkan kode induk dari WORKA.
     *
     * Hanya unit yang dikirim WORKA pada putaran ini yang disentuh; unit lokal
     * di luar daftar (mis. dibuat manual oleh admin) dibiarkan apa adanya.
     *
     * @param  array<string, string|null>  $kodeInduk
     * @param  array<string, true>  $tersentuh
     * @return array<string, true>
     */
    protected function tautkanInduk(array $kodeInduk, array $tersentuh): array
    {
        $unitPerKode = UnitKerja::query()
            ->whereIn('kode', array_keys($kodeInduk))
            ->get()
            ->keyBy('kode');

        foreach ($kodeInduk as $kode => $induk) {
            $lokal = $unitPerKode->get($kode);

            if ($lokal === null) {
                continue;
            }

            // Unit puncak tidak berinduk; unit yang induknya dilepas di WORKA
            // ikut dilepas di sini agar hirarki tidak tertinggal versi lama.
            $indukId = null;

            if ($induk !== null && $induk !== $kode) {
                $indukId = $unitPerKode->get($induk)?->id;

                if ($indukId === null) {
                    // Induk di luar daftar yang dikirim WORKA: biarkan tautan
                    // lama daripada memutusnya berdasarkan data tak lengkap.
                    Log::channel('worka')->warning('Induk unit kerja tidak ditemukan saat sinkronisasi.', [
                        'unit_kerja_kode' => $kode,
                        'induk_kode' => $induk,
                    ]);

                    continue;
                }
            }

            $lokal->induk_id = $indukId;

            if ($lokal->isDirty()) {
                $lokal->save();
                $tersentuh[$kode] = true;
            }
        }

        return $tersentuh;
    }

    /**
     * Tahap ketiga: tegakkan induk unit kerja milik SI-ABSEN sendiri.
     *
     * Unit lokal seperti DISNAKER (tempat kepala dinas) tidak pernah dikirim
     * WORKA, jadi induknya tidak dapat ditarik dari jawaban API. Peta
     * `services.worka.induk_unit_lokal` menyatakan induk yang dikehendaki
     * dalam bentuk kode, dan tautannya ditegakkan ulang di sini setiap kali
     * sinkronisasi berjalan.
     *
     * Idempoten: menjalankannya berkali-kali tidak mengubah apa pun setelah
     * tautan benar, dan hirarki pulih sendiri bila unit induk baru muncul
     * pada sinkronisasi berikutnya — tanpa bergantung urutan seeding.
     *
     * @param  array<int, string>  $kodeDariWorka  kode unit yang dikirim WORKA pada putaran ini
     * @param  array<string, true>  $tersentuh
     * @return array<string, true>
     */
    protected function tautkanUnitLokal(array $kodeDariWorka, array $tersentuh): array
    {
        /** @var array<string, string> $peta */
        $peta = config('services.worka.induk_unit_lokal', []);

        if ($peta === []) {
            return $tersentuh;
        }

        $unitPerKode = UnitKerja::query()
            ->whereIn('kode', array_merge(array_keys($peta), array_values($peta)))
            ->get()
            ->keyBy('kode');

        foreach ($peta as $kode => $kodeInduk) {
            $lokal = $unitPerKode->get($kode);

            // Unit yang ternyata dikirim WORKA bukan lagi urusan peta ini:
            // hirarki dari WORKA yang berlaku, jangan ditimpa.
            if ($lokal === null || $kode === $kodeInduk || in_array($kode, $kodeDariWorka, true)) {
                continue;
            }

            $induk = $unitPerKode->get($kodeInduk);

            if ($induk === null) {
                // Induk belum ada — biasanya karena WORKA belum pernah
                // disinkronkan. Dibiarkan agar putaran berikutnya menautkan.
                Log::channel('worka')->warning('Induk unit kerja lokal belum tersedia.', [
                    'unit_kerja_kode' => $kode,
                    'induk_kode' => $kodeInduk,
                ]);

                continue;
            }

            $lokal->induk_id = $induk->id;

            if ($lokal->isDirty()) {
                $lokal->save();
                $tersentuh[$kode] = true;
            }
        }

        return $tersentuh;
    }

    /**
     * Kode unit induk dari satu baris unit kerja WORKA.
     *
     * WORKA mengirimkan induk sebagai objek `parent` ({id, kode, nama}) dan
     * bernilai null pada unit puncak. Bentuk lain (kunci `induk`, atau kode
     * sebagai skalar) ikut dikenali agar sinkronisasi tidak patah bila
     * penamaan medan di WORKA bergeser.
     *
     * @param  array<string, mixed>  $unit
     */
    protected function kodeInduk(array $unit): ?string
    {
        foreach (['parent', 'induk'] as $medan) {
            $nilai = $unit[$medan] ?? null;
            $kode = is_array($nilai) ? ($nilai['kode'] ?? null) : $nilai;

            if (is_string($kode) && $kode !== '') {
                return $kode;
            }
        }

        return null;
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
