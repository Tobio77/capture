<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Enums\StatusRiwayatLaporan;
use App\Jobs\BuatBackupJob;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\RiwayatBackup;
use App\Models\UnitKerja;
use App\Models\User;
use App\Support\PengaturanRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Maintenance & Backup — arsip .zip berisi data absensi (FR-MTN-01).
 *
 * Cakupannya SENGAJA terbatas: baris database yang membentuk riwayat
 * kehadiran (unit kerja, pegawai, kiosk, event/sesi, absensi) — BUKAN
 * berkas foto (absen maupun referensi wajah) dan BUKAN tabel `users`,
 * `log_aktivitas`, `pengaturan`. Ini backup DATA ABSENSI, bukan snapshot
 * penuh server; disebutkan eksplisit di layar Maintenance supaya admin
 * tidak salah kira.
 *
 * Dua jalur pemicu, satu logika inti ({@see self::jalankanSinkron()}):
 * tombol manual mengantre lewat `dispatch(...)->afterResponse()` — pola
 * yang sama dengan Riwayat Laporan, dengan alasan yang sama (lihat
 * BuatBackupJob) — sementara perintah terjadwal memanggilnya LANGSUNG,
 * sebab tidak ada permintaan HTTP yang menunggu jawaban.
 */
class BackupService
{
    public const string KUNCI_RETENSI_HARI = 'backup.retensi_hari';

    public const int RETENSI_BAWAAN_HARI = 30;

    public const int RETENSI_MIN_HARI = 7;

    public const int RETENSI_MAKS_HARI = 365;

    public const string DISK = 'local';

    /**
     * Tabel yang tercakup backup, urutan ketergantungan FK — unit kerja
     * lebih dulu, absensi terakhir. Urutan ini dipakai APA ADANYA saat
     * memulihkan (lihat pulihkan()), bukan cuma catatan: baris anak tidak
     * boleh ditulis sebelum baris induk yang ditunjuknya ada.
     *
     * Nilai `null` berarti tabel pivot murni tanpa model Eloquent —
     * `event_unit_kerja` diakses lewat relasi belongsToMany, dibaca/ditulis
     * langsung lewat query builder di sini.
     *
     * @var array<string, class-string<Model>|null>
     */
    protected const array TABEL = [
        'unit_kerja' => UnitKerja::class,
        'pegawai' => Pegawai::class,
        'kiosk' => Kiosk::class,
        'event_absen' => EventAbsen::class,
        'event_unit_kerja' => null,
        'absensi' => Absensi::class,
    ];

    /**
     * unit_kerja menaut ke dirinya sendiri lewat `induk_id` — baris anak
     * bisa saja muncul lebih dulu daripada induknya dalam arsip, dan
     * menulisnya apa adanya bisa menabrak batasan FK sebelum baris induknya
     * sempat ada. Dipulihkan dua tahap: `induk_id` dikosongkan dulu (tahap
     * pertama, aman ditulis urutan apa pun), lalu ditegakkan ulang begitu
     * SELURUH baris unit_kerja sudah pasti ada (tahap kedua).
     */
    protected const array KOSONGKAN_DULU = [
        'unit_kerja' => ['induk_id'],
    ];

    public function __construct(
        protected PengaturanRepository $pengaturan,
        protected LogAktivitasService $log,
    ) {}

    public function retensiHari(): int
    {
        $nilai = $this->pengaturan->ambil(self::KUNCI_RETENSI_HARI);

        return $nilai === null || ! is_numeric($nilai) ? self::RETENSI_BAWAAN_HARI : (int) $nilai;
    }

    public function simpanRetensi(int $hari): void
    {
        $this->pengaturan->simpan(self::KUNCI_RETENSI_HARI, (string) $hari);
    }

    /**
     * Antrekan backup manual — dipicu tombol admin di halaman Maintenance.
     */
    public function buatManual(User $pemicu): RiwayatBackup
    {
        $riwayat = RiwayatBackup::query()->create([
            'dipicu_oleh' => 'manual',
            'user_id' => $pemicu->id,
            'status' => StatusRiwayatLaporan::Antre,
            'nama_berkas' => $this->namaBerkas(),
        ]);

        /*
         * `afterResponse()`, bukan antrian sungguhan — lihat catatan lengkap
         * di BuatBackupJob. Permintaan HTTP tetap dijawab seketika; arsipnya
         * dirakit sesaat sesudahnya pada proses yang sama.
         */
        BuatBackupJob::dispatch($riwayat->id)->afterResponse();

        return $riwayat;
    }

    /**
     * Backup terjadwal — dipanggil BackupAbsensiCommand, LANGSUNG (sinkron):
     * tidak ada permintaan HTTP yang menunggu jawaban di sini.
     */
    public function buatTerjadwal(): RiwayatBackup
    {
        $riwayat = RiwayatBackup::query()->create([
            'dipicu_oleh' => 'terjadwal',
            'user_id' => null,
            'status' => StatusRiwayatLaporan::Antre,
            'nama_berkas' => $this->namaBerkas(),
        ]);

        $this->jalankanSinkron($riwayat);

        return $riwayat;
    }

    protected function namaBerkas(): string
    {
        return 'backup-absensi-'.Carbon::now()->format('Ymd-His').'.zip';
    }

    /**
     * Logika inti — dipakai BuatBackupJob (jalur manual) MAUPUN
     * buatTerjadwal() (jalur terjadwal) langsung, tanpa duplikasi.
     */
    public function jalankanSinkron(RiwayatBackup $riwayat): void
    {
        $riwayat->update(['status' => StatusRiwayatLaporan::Diproses]);

        try {
            $arsip = $this->susunArsip();
            $isi = file_get_contents($arsip);
            $ukuran = strlen($isi);
            unlink($arsip);

            $tujuan = "backup/{$riwayat->id}-{$riwayat->nama_berkas}";
            Storage::disk(self::DISK)->put($tujuan, $isi);

            $riwayat->update([
                'status' => StatusRiwayatLaporan::Selesai,
                'path' => $tujuan,
                'ukuran_bytes' => $ukuran,
                'selesai_pada' => now(),
            ]);
        } catch (Throwable $e) {
            // Pesan lengkapnya ke log untuk ditelusuri, bukan ke admin —
            // jejak tumpukan panggilan tidak ada gunanya bagi yang membaca
            // Riwayat, dan berpotensi membocorkan struktur aplikasi.
            Log::error('Backup data absensi gagal', [
                'riwayat_id' => $riwayat->id,
                'exception' => $e,
            ]);

            $riwayat->update([
                'status' => StatusRiwayatLaporan::Gagal,
                'pesan_galat' => 'Backup gagal dibuat. Coba lagi, atau hubungi admin bila terus terjadi.',
            ]);
        }
    }

    /**
     * Rakit arsip .zip berisi satu berkas JSON Lines per tabel, ditulis
     * langsung ke disk lewat query berpotongan (`chunkById`) — bukan
     * `get()` sekaligus. Pelajaran dari bug memori PDF sebelumnya di sesi
     * ini: jangan muat tabel besar ke memori utuh sekaligus, betapapun
     * kecil tabelnya SEKARANG.
     */
    protected function susunArsip(): string
    {
        $direktori = $this->direktoriSementara();
        $arsip = tempnam($direktori, 'backup-absensi-');
        unlink($arsip);
        $arsip .= '.zip';

        $zip = new ZipArchive;
        $zip->open($arsip, ZipArchive::CREATE);

        $berkasSementara = [];

        try {
            foreach (self::TABEL as $nama => $kelas) {
                $berkas = tempnam($direktori, "backup-{$nama}-");
                $berkasSementara[] = $berkas;

                $this->tulisTabel($nama, $kelas, $berkas);
                $zip->addFile($berkas, "{$nama}.json");
            }

            $zip->close();
        } finally {
            // addFile() menaut berkas sementara sampai close() dipanggil —
            // dihapus SESUDAHNYA, bukan sebelum, atau zip-nya kosong.
            foreach ($berkasSementara as $berkas) {
                if (is_file($berkas)) {
                    unlink($berkas);
                }
            }
        }

        return $arsip;
    }

    /**
     * @param  class-string<Model>|null  $kelas  null untuk tabel pivot tanpa model Eloquent
     */
    protected function tulisTabel(string $nama, ?string $kelas, string $berkas): void
    {
        $pegangan = fopen($berkas, 'w');

        if ($kelas !== null) {
            $kelas::query()->orderBy('id')->chunkById(500, function ($potongan) use ($pegangan) {
                foreach ($potongan as $baris) {
                    fwrite($pegangan, json_encode($baris->getAttributes())."\n");
                }
            });
        } else {
            DB::table($nama)->orderBy('id')->chunkById(500, function ($potongan) use ($pegangan) {
                foreach ($potongan as $baris) {
                    fwrite($pegangan, json_encode((array) $baris)."\n");
                }
            });
        }

        fclose($pegangan);
    }

    /**
     * Pulihkan (restore) data dari sebuah backup — MENGGABUNGKAN, bukan
     * mengganti total (FR-MTN-02): tiap baris arsip ditulis lewat
     * `upsert()` per `id` — baris yang sudah ada diperbarui ke nilai
     * backup, baris yang belum ada ditambahkan. Baris yang dibuat
     * SESUDAH backup ini dan TIDAK ADA di dalamnya TIDAK disentuh sama
     * sekali — restore tidak pernah menghapus apa pun sendirian.
     *
     * Struktur arsip divalidasi LEBIH DAHULU, sebelum satu baris pun
     * ditulis: baik seluruhnya berhasil, atau tidak ada yang berubah.
     *
     * @return array<string, int> jumlah baris yang diproses per tabel, untuk audit trail
     */
    public function pulihkan(RiwayatBackup $riwayat, User $pelaku): array
    {
        $arsipSementara = tempnam($this->direktoriSementara(), 'pulihkan-');
        file_put_contents($arsipSementara, Storage::disk(self::DISK)->get($riwayat->path));

        try {
            $zip = new ZipArchive;

            if ($zip->open($arsipSementara) !== true) {
                throw new RuntimeException('Berkas backup tidak dapat dibuka atau rusak.');
            }

            try {
                $this->validasiStrukturArsip($zip);

                $ringkasan = [];

                DB::transaction(function () use ($zip, &$ringkasan) {
                    foreach (self::TABEL as $nama => $kelas) {
                        $kosongkanDulu = self::KOSONGKAN_DULU[$nama] ?? [];

                        // Tabel dengan kolom yang perlu dikosongkan dulu
                        // (unit_kerja.induk_id): tahap pertama menulis SELURUH
                        // baris dengan kolom itu null — aman ditulis urutan
                        // apa pun — baru tahap kedua menegakkan nilai
                        // sesungguhnya, setelah seluruh baris tabel itu
                        // dipastikan ada.
                        if ($kosongkanDulu !== []) {
                            $this->pulihkanTabel($zip, $nama, $kosongkanDulu);
                        }

                        $ringkasan[$nama] = $this->pulihkanTabel($zip, $nama);
                    }
                });

                $this->log->catat(
                    AksiLog::PulihkanBackup,
                    sprintf(
                        'Memulihkan data dari backup "%s" (%s).',
                        $riwayat->nama_berkas,
                        collect($ringkasan)->map(fn ($jumlah, $nama) => "{$nama}: {$jumlah}")->implode(', '),
                    ),
                    user: $pelaku,
                );

                return $ringkasan;
            } finally {
                $zip->close();
            }
        } finally {
            unlink($arsipSementara);
        }
    }

    /**
     * Seluruh tabel yang diharapkan harus ada di arsip SEBELUM satu baris
     * pun ditulis — arsip yang rusak/tidak lengkap ditolak mentah-mentah,
     * bukan memulihkan sebagian lalu berhenti di tengah jalan.
     */
    protected function validasiStrukturArsip(ZipArchive $zip): void
    {
        foreach (array_keys(self::TABEL) as $nama) {
            if ($zip->locateName("{$nama}.json") === false) {
                throw new RuntimeException("Berkas backup tidak lengkap — tabel \"{$nama}\" tidak ditemukan di dalamnya.");
            }
        }
    }

    /**
     * @param  array<int, string>  $kosongkanKolom  kolom yang dipaksa null pada tahap ini
     * @return int jumlah baris yang diproses
     */
    protected function pulihkanTabel(ZipArchive $zip, string $nama, array $kosongkanKolom = []): int
    {
        $aliran = $zip->getStream("{$nama}.json");

        if ($aliran === false) {
            throw new RuntimeException("Tidak dapat membaca tabel \"{$nama}\" dari backup.");
        }

        $jumlah = 0;
        $kelompok = [];

        try {
            while (($baris = fgets($aliran)) !== false) {
                $baris = trim($baris);

                if ($baris === '') {
                    continue;
                }

                $baris = json_decode($baris, true);

                if (! is_array($baris) || ! isset($baris['id'])) {
                    throw new RuntimeException("Baris tidak sah pada tabel \"{$nama}\" di dalam backup.");
                }

                foreach ($kosongkanKolom as $kolom) {
                    $baris[$kolom] = null;
                }

                $kelompok[] = $baris;
                $jumlah++;

                if (count($kelompok) >= 500) {
                    $this->upsertKelompok($nama, $kelompok);
                    $kelompok = [];
                }
            }

            if ($kelompok !== []) {
                $this->upsertKelompok($nama, $kelompok);
            }
        } finally {
            fclose($aliran);
        }

        return $jumlah;
    }

    /**
     * @param  array<int, array<string, mixed>>  $baris
     */
    protected function upsertKelompok(string $tabel, array $baris): void
    {
        if ($baris === []) {
            return;
        }

        DB::table($tabel)->upsert($baris, ['id'], array_keys($baris[0]));
    }

    protected function direktoriSementara(): string
    {
        $direktori = storage_path('app/backup-tmp');

        if (! is_dir($direktori)) {
            mkdir($direktori, recursive: true);
        }

        return $direktori;
    }

    /**
     * Hapus backup SELESAI yang sudah melampaui retensi — dipanggil sesudah
     * setiap backup terjadwal, bukan lewat perintah terpisah.
     */
    public function bersihkanKedaluwarsa(): int
    {
        $ambang = Carbon::now()->subDays($this->retensiHari());

        $kedaluwarsa = RiwayatBackup::query()
            ->where('status', StatusRiwayatLaporan::Selesai->value)
            ->where('selesai_pada', '<', $ambang)
            ->get();

        foreach ($kedaluwarsa as $riwayat) {
            $this->hapus($riwayat);
        }

        return $kedaluwarsa->count();
    }

    /**
     * Bentuk siap-tampil untuk Inertia — dipanggil saat halaman dimuat
     * maupun dari endpoint polling.
     *
     * @return array<int, array<string, mixed>>
     */
    public function untukLayar(): array
    {
        return RiwayatBackup::query()
            ->with('user:id,nama')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (RiwayatBackup $riwayat) => [
                'id' => $riwayat->id,
                'dipicu_oleh' => $riwayat->dipicu_oleh,
                'status' => $riwayat->status->value,
                'status_label' => $riwayat->status->label(),
                'nama_berkas' => $riwayat->nama_berkas,
                'ukuran_label' => $this->ukuranLabel($riwayat->ukuran_bytes),
                'pesan_galat' => $riwayat->pesan_galat,
                'dibuat_pada' => $riwayat->created_at->translatedFormat('d M Y H:i'),
                'dibuat_oleh' => $riwayat->user?->nama,
            ])
            ->all();
    }

    protected function ukuranLabel(?int $bytes): ?string
    {
        if ($bytes === null) {
            return null;
        }

        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }

    public function hapus(RiwayatBackup $riwayat): void
    {
        if ($riwayat->path !== null) {
            Storage::disk(self::DISK)->delete($riwayat->path);
        }

        $riwayat->delete();
    }
}
