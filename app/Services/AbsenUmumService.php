<?php

namespace App\Services;

use App\Enums\CakupanEvent;
use App\Enums\JenisEvent;
use App\Enums\StatusEvent;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Absen umum — sesi absen harian tanpa event kegiatan.
 *
 * Absensi di Capture selalu bernaung pada sebuah event: kunci unik
 * (event, pegawai, jenis) itulah yang membuat satu pegawai tidak dapat
 * mencatat "datang" dua kali. Absen harian karena itu tidak dibuat sebagai
 * jalur terpisah, melainkan sebagai satu sesi event berjenis
 * {@see JenisEvent::Umum} per unit kerja per tanggal, yang dibuka sistem
 * sendiri saat pertama kali dibutuhkan.
 *
 * Akibatnya seluruh mesin yang sudah ada — pencatatan, verifikasi wajah,
 * foto, rekap, laporan — bekerja pada absen umum tanpa perubahan, sementara
 * kunci uniknya tetap berarti "satu kali datang per hari".
 *
 * Sesi umum tidak pernah menghalangi kegiatan, dan sejak revisi S29 juga tidak
 * pernah didahului kegiatan: keduanya dua layar terpisah pada perangkat absen,
 * dipilih petugas dari beranda, bukan satu layar yang berpindah isi sendiri
 * (lihat {@see TitikAbsenService}). FR-EVT-06 pun hanya berlaku antar event
 * kegiatan — sesi harian tidak pernah ikut dihitung bentrok.
 */
class AbsenUmumService
{
    public function __construct(
        protected SettingAbsenService $setting,
        protected AbsensiService $absensi,
    ) {}

    /**
     * Apakah absen umum dinyalakan admin pada Setting Absen.
     */
    public function aktif(): bool
    {
        return (bool) $this->setting->ambil()['absen_umum_aktif'];
    }

    /**
     * Sesi absen umum sebuah unit kerja pada satu tanggal.
     *
     * `$buat` sengaja tidak default true: layar dan pemantauan hanya membaca,
     * sehingga perangkat yang menyala sepanjang hari libur tidak meninggalkan
     * sesi kosong. Sesi baru lahir ketika benar-benar ada yang akan mengabsen.
     */
    public function sesi(int $unitKerjaId, ?Carbon $tanggal = null, bool $buat = false): ?EventAbsen
    {
        $tanggal ??= Carbon::today();
        $unitTeratas = UnitKerja::idTeratasUntuk($unitKerjaId) ?? $unitKerjaId;

        $sesi = EventAbsen::query()
            ->umum()
            ->where('kunci_sesi', self::kunci($unitTeratas, $tanggal))
            ->first();

        if ($sesi !== null || ! $buat) {
            return $sesi;
        }

        return $this->aktif() ? $this->buatSesi($unitTeratas, $tanggal) : null;
    }

    /**
     * Penanda satu sesi harian: satu unit kerja, satu tanggal.
     *
     * Cakupan sesi tersimpan pada tabel pivot, sehingga kunci uniknya tidak
     * dapat dirakit dari kolom biasa. Nilai ini yang menempati kolom unik
     * `event_absen.kunci_sesi`.
     */
    public static function kunci(int $unitKerjaId, Carbon $tanggal): string
    {
        return "umum:{$unitKerjaId}:{$tanggal->toDateString()}";
    }

    /**
     * Sesi absen umum yang melayani sebuah perangkat absen.
     */
    public function sesiUntukKiosk(Kiosk $kiosk, bool $buat = false): ?EventAbsen
    {
        if ($kiosk->unit_kerja_id === null) {
            return null;
        }

        return $this->sesi($kiosk->unit_kerja_id, buat: $buat);
    }

    /**
     * Sesi absen umum yang dilayani layar absen di peramban admin.
     *
     * Admin UPT terkunci pada unitnya sendiri; peran lintas unit memilih unit
     * mana yang sedang dilayaninya. Tanpa pilihan, tidak ada sesi — layar
     * absen menampilkan pemilih unit alih-alih menerima tap sembarangan.
     */
    public function sesiUntukAdmin(User $pelaku, ?int $unitKerjaId = null, bool $buat = false): ?EventAbsen
    {
        $unit = $this->unitTerpilih($pelaku, $unitKerjaId);

        return $unit === null ? null : $this->sesi($unit, buat: $buat);
    }

    /**
     * Unit kerja yang boleh dilayani seorang admin pada layar absen umum.
     */
    public function unitTerpilih(User $pelaku, ?int $unitKerjaId): ?int
    {
        if (! $pelaku->lintasUnit()) {
            // Pilihan Admin UPT diabaikan: cakupannya sudah ditentukan akun.
            return UnitKerja::idTeratasUntuk($pelaku->unit_kerja_id);
        }

        if ($unitKerjaId === null) {
            return null;
        }

        // Simpul OPD sendiri bukan anggota `levelTeratas()` — ia induknya —
        // sehingga harus diizinkan terpisah; lihat self::unitTersedia().
        if ($unitKerjaId === UnitKerja::idOpd()) {
            return $unitKerjaId;
        }

        return UnitKerja::query()->levelTeratas()->whereKey($unitKerjaId)->exists()
            ? $unitKerjaId
            : null;
    }

    /**
     * Unit yang dapat dipilih pada layar dan pemantauan absen umum.
     *
     * Pilihan pertama bagi peran lintas unit adalah SIMPUL OPD sendiri —
     * `DISNAKERTRANS`, induk seluruh UPT dan bidang — bukan salah satu unit
     * level teratas. Absen harian berlaku bagi seluruh pegawai dinas, sehingga
     * bawaan yang benar adalah sesi yang mencakup semuanya; sebelum revisi
     * S29, bawaannya jatuh ke unit pertama menurut abjad ("Bidang Hubungan
     * Industrial…") semata-mata karena itulah baris pertama daftar, dan admin
     * yang tidak menyadarinya memantau sesi yang salah.
     *
     * Simpul OPD memang bukan anggota `levelTeratas()` — ia justru induk dari
     * seluruh anggotanya — dan karena itu ditambahkan terpisah di sini.
     * Cakupannya lewat {@see UnitKerja::idsDenganTurunan()} sudah meliputi
     * `DISNAKER`, seluruh UPT, seluruh bidang, beserta seksi/subbag di
     * bawahnya.
     *
     * Sesi OPD berdiri sendiri, terpisah dari sesi per-UPT: perangkat absen di
     * sebuah UPT tetap jatuh ke sesi UPT-nya ({@see self::sesiUntukKiosk()}),
     * karena itulah satuan tempat kehadiran hariannya direkap.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function unitTersedia(User $pelaku): Collection
    {
        $teratas = UnitKerja::query()
            ->levelTeratas()
            ->aktif()
            ->when(
                ! $pelaku->lintasUnit(),
                fn ($q) => $q->whereIn('id', UnitKerja::idTeratasMenaungi($pelaku->unit_kerja_id)),
            )
            ->orderBy('nama')
            ->get(['id', 'kode', 'nama'])
            ->map(fn (UnitKerja $unit) => $unit->only(['id', 'kode', 'nama']));

        if (! $pelaku->lintasUnit()) {
            return $teratas;
        }

        $opd = UnitKerja::query()
            ->whereKey(UnitKerja::idOpd())
            ->first(['id', 'kode', 'nama']);

        // Instalasi yang belum pernah menyinkronkan WORKA belum punya simpul
        // OPD; daftarnya tetap terisi unit level teratas apa adanya.
        return $opd === null
            ? $teratas
            : $teratas->prepend($opd->only(['id', 'kode', 'nama']))->values();
    }

    /**
     * Riwayat sesi absen umum sebuah unit, terbaru lebih dahulu.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function riwayat(int $unitKerjaId, int $batas = 14): Collection
    {
        return EventAbsen::query()
            ->umum()
            ->whereHas('unitKerja', fn ($unit) => $unit->where('unit_kerja.id', $unitKerjaId))
            ->withCount('absensi')
            ->orderByDesc('tanggal')
            ->limit($batas)
            ->get()
            ->map(fn (EventAbsen $sesi) => [
                'id' => $sesi->id,
                'tanggal' => $sesi->tanggal->toDateString(),
                'jam_mulai' => substr((string) $sesi->jam_mulai, 0, 5),
                'jumlah_absen' => $sesi->absensi_count,
                'ditutup' => ! $sesi->aktif(),
            ]);
    }

    /**
     * Buka sesi hari ini secara eksplisit, dipakai tombol "Buka Sesi" pada
     * halaman pemantauan.
     */
    public function buka(int $unitKerjaId, ?Carbon $tanggal = null): ?EventAbsen
    {
        return $this->sesi($unitKerjaId, $tanggal, buat: true);
    }

    /**
     * Sesi baru untuk satu unit pada satu tanggal.
     *
     * Jam masuk dan toleransi disalin dari Setting Absen saat sesi dibuat,
     * mengikuti perlakuan yang sama pada event kegiatan (FR-SET-02): menggeser
     * setting global tidak boleh mengubah penilaian tepat/terlambat sesi yang
     * sudah berjalan.
     */
    protected function buatSesi(int $unitKerjaId, Carbon $tanggal): EventAbsen
    {
        $setting = $this->setting->ambil();
        $unit = UnitKerja::query()->find($unitKerjaId);
        $kunci = self::kunci($unitKerjaId, $tanggal);

        try {
            $sesi = EventAbsen::create([
                'nama' => 'Absen Umum'.($unit === null ? '' : " — {$unit->nama}"),
                'jenis' => JenisEvent::Umum,
                'kunci_sesi' => $kunci,
                'tanggal' => $tanggal->toDateString(),
                'jam_mulai' => $setting['jam_masuk_umum'].':00',
                'toleransi_menit' => $setting['toleransi_default_menit'],
                'cakupan' => CakupanEvent::Unit,
                'status' => StatusEvent::Aktif,

                // Tidak ada pembuat: sesi ini dibuka sistem, bukan seorang admin.
                'dibuat_oleh' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            /*
             * Titik absen lain di unit yang sama membuka sesi hari ini lebih
             * dahulu, terpaut milidetik. Tanpa kunci unik, keduanya akan lahir
             * dan tap berikutnya jatuh ke salah satunya secara tak tentu —
             * membuat penolakan tap ganda (FR-TAP-05) tidak pernah kena.
             */
            return EventAbsen::query()
                ->umum()
                ->where('kunci_sesi', $kunci)
                ->firstOrFail()
                ->load('unitKerja:id,kode,nama');
        }

        $sesi->unitKerja()->attach($unitKerjaId);

        return $sesi->load('unitKerja:id,kode,nama');
    }
}
