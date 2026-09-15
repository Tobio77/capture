<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Enums\CakupanEvent;
use App\Enums\JenisAbsen;
use App\Enums\JenisEvent;
use App\Enums\OverrideAbsenUmum;
use App\Enums\StatusEvent;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Support\StatusAbsenUmum;
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
        protected LogAktivitasService $log,
        protected KalenderKerjaService $kalender,
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
     * Status efektif Absen Umum untuk satu jenis absen (FR-SET-07).
     *
     * Urutan resolusinya tetap, dan keempatnya harus disebut eksplisit karena
     * beberapa di antaranya menghasilkan layar yang terlihat sama persis:
     *
     *   1. Absen umum dimatikan pada Setting Absen → tertutup, tanpa kecuali.
     *   2. Sesi hari ini membawa override admin → override menang, apa pun
     *      kata kalender maupun jadwal.
     *   3. Bukan hari kerja bagi unit ini (akhir pekan atau hari libur
     *      terdaftar) → tertutup otomatis. Revisi kebijakan S39: sebelum ini,
     *      hari libur hanya menandai tanpa menutup, dan satu-satunya yang
     *      membuka jendelanya kembali adalah override di langkah 2 — sengaja
     *      diperiksa lebih dahulu supaya petugas piket yang memang ditugaskan
     *      tetap bisa dibukakan.
     *   4. Selebihnya → di dalam jendela jam bawaan berarti terbuka.
     *
     * `$unitKerjaId` diminta eksplisit dari pemanggil, bukan diturunkan dari
     * `$sesi`: langkah 3 harus berlaku bahkan SEBELUM sesi harian lahir —
     * sebelum tap pertama, unit sudah diketahui (kiosk tahu unitnya sendiri,
     * admin sudah memilih unit di layar), sedangkan sesinya belum tentu ada.
     */
    public function status(
        JenisAbsen $jenis,
        ?int $unitKerjaId,
        ?EventAbsen $sesi = null,
        ?Carbon $waktu = null,
    ): StatusAbsenUmum {
        $setting = $this->setting->ambil();
        $waktu ??= Carbon::now();

        $alasanLibur = $this->kalender->alasanLibur($unitKerjaId, $waktu->copy()->startOfDay());

        /*
         * Jadwal HARI YANG DIEVALUASI ($waktu), bukan selalu "hari ini" —
         * status() sudah menerima $waktu sebagai parameter eksplisit (dipakai
         * pengujian dan penelusuran ulang), dan jadwal per hari harus ikut
         * kaidah yang sama alih-alih diam-diam memakai jam hari sekarang.
         */
        $jadwalHari = $this->setting->jadwalUntukHari($waktu->dayOfWeekIso);

        [$buka, $tutup] = $jenis === JenisAbsen::Datang
            ? [$jadwalHari['jam_buka_datang'], $jadwalHari['jam_tutup_datang']]
            : [$jadwalHari['jam_buka_pulang'], $jadwalHari['jam_tutup_pulang']];

        if (! $setting['absen_umum_aktif']) {
            return new StatusAbsenUmum($jenis, false, 'setting', $buka, $tutup, alasanLibur: $alasanLibur);
        }

        $override = $sesi?->override_absen;

        if ($override !== null) {
            return new StatusAbsenUmum(
                $jenis,
                $override->terbuka(),
                'override',
                $buka,
                $tutup,
                $override,
                $sesi->pemasangOverride?->nama,
                $alasanLibur,
            );
        }

        if ($alasanLibur !== null) {
            return new StatusAbsenUmum($jenis, false, 'kalender', $buka, $tutup, alasanLibur: $alasanLibur);
        }

        return new StatusAbsenUmum(
            $jenis,
            $this->didalamJendela($waktu, $buka, $tutup),
            'jadwal',
            $buka,
            $tutup,
        );
    }

    /**
     * Status kedua jenis sekaligus, untuk layar yang menampilkan keduanya.
     *
     * @return array<string, StatusAbsenUmum>
     */
    public function statusSemua(?int $unitKerjaId, ?EventAbsen $sesi = null, ?Carbon $waktu = null): array
    {
        return [
            JenisAbsen::Datang->value => $this->status(JenisAbsen::Datang, $unitKerjaId, $sesi, $waktu),
            JenisAbsen::Pulang->value => $this->status(JenisAbsen::Pulang, $unitKerjaId, $sesi, $waktu),
        ];
    }

    /**
     * Apakah jam sekarang berada di dalam jendela.
     *
     * Jendela yang jam tutupnya LEBIH AWAL daripada jam bukanya dianggap
     * melewati tengah malam — sif malam yang pulangnya pukul 02.00 bukan
     * kemustahilan di UPT yang menyelenggarakan pelatihan menginap.
     */
    protected function didalamJendela(Carbon $waktu, string $buka, string $tutup): bool
    {
        $menit = (int) $waktu->format('G') * 60 + (int) $waktu->format('i');
        $awal = $this->keMenit($buka);
        $akhir = $this->keMenit($tutup);

        return $awal <= $akhir
            ? $menit >= $awal && $menit <= $akhir
            : $menit >= $awal || $menit <= $akhir;
    }

    protected function keMenit(string $jam): int
    {
        [$j, $m] = array_map('intval', explode(':', $jam) + [1 => '0']);

        return $j * 60 + $m;
    }

    /**
     * Pasang atau cabut override pada sesi hari ini.
     *
     * Sesinya dibuat bila belum ada: admin yang menekan "buka paksa" pukul
     * lima sore memang bermaksud membuka sesi hari ini, dan menolak karena
     * "sesinya belum lahir" hanya akan membingungkan.
     */
    public function aturOverride(int $unitKerjaId, ?OverrideAbsenUmum $override, User $pelaku): ?EventAbsen
    {
        $sesi = $this->sesi($unitKerjaId, buat: true);

        if ($sesi === null) {
            return null;
        }

        $sesi->update([
            'override_absen' => $override,
            'override_oleh' => $override === null ? null : $pelaku->id,
            'override_pada' => $override === null ? null : Carbon::now(),
        ]);

        $this->log->catat(
            AksiLog::Ubah,
            $override === null
                ? "Mencabut override Absen Umum pada {$sesi->nama}; kembali mengikuti jadwal."
                : "{$override->label()} Absen Umum pada {$sesi->nama} untuk hari ini.",
            user: $pelaku,
            subjek: $sesi,
        );

        return $sesi->fresh();
    }

    /**
     * Rekap sebuah sesi absen umum, lengkap dengan ringkasannya.
     *
     * Satu-satunya tempat pertanyaan "siapa saja yang absen umum pada tanggal
     * ini" dijawab. Halaman Absen Umum dan tab Rekap Umum sama-sama memanggil
     * ini; keduanya sempat hendak ditulis sendiri-sendiri, dan pengalaman
     * dengan pengisian Jam Masuk/Jam Pulang yang sempat menyimpang antar-jalur
     * membuat salinan kedua itu tidak sepadan risikonya. Satu jawaban berarti
     * satu tempat untuk diperbaiki, dan satu tempat untuk diuji.
     *
     * Cakupannya mengikuti peran (FR-REK-02): Admin UPT hanya melihat
     * pegawainya sendiri, walaupun sesi yang dibacanya milik unit yang
     * menaunginya.
     *
     * @return array{sesi: ?EventAbsen, baris: Collection<int, array<string, mixed>>, ringkasan: array<string, mixed>}
     */
    public function rekapHarian(
        User $pelaku,
        ?int $unitKerjaId,
        ?Carbon $tanggal = null,
        string $cari = '',
    ): array {
        $sesi = $unitKerjaId === null ? null : $this->sesi($unitKerjaId, $tanggal);

        $baris = $sesi === null
            ? collect()
            : $this->saring($this->absensi->rekap($sesi, $this->cakupan($pelaku)), $cari);

        return [
            'sesi' => $sesi,
            'baris' => $baris,
            'ringkasan' => $this->ringkasan($baris, $unitKerjaId),
        ];
    }

    /**
     * Rekap Absen Umum lintas BEBERAPA hari sekaligus (Bagian 4, revisi
     * rentang tanggal) — satu unit, satu baris per sesi harian yang memang
     * ada dalam rentangnya. `tanggal`/`tanggal_label` ditambahkan pada tiap
     * baris supaya pegawai yang sama pada hari berbeda tetap terbedakan di
     * tabel — TabelRekap.vue menampilkan kolom itu hanya ketika rentangnya
     * lebih dari satu hari.
     *
     * Berdiri terpisah dari rekapHarian() alih-alih menjadikannya kasus
     * `$sampai === null`: rentang tidak punya konsep "sesi tunggal" (tombol
     * override/buka-sesi pada layar Absen Umum operasional tidak berarti
     * apa-apa di sini), dan ringkasannya beda bentuk — tidak ada "pegawai
     * aktif"/"belum absen" yang berarti dihitung lintas banyak hari sekaligus.
     *
     * @return array{baris: Collection<int, array<string, mixed>>, ringkasan: array<string, mixed>}
     */
    public function rekapRentang(
        User $pelaku,
        ?int $unitKerjaId,
        Carbon $dari,
        Carbon $sampai,
        string $cari = '',
    ): array {
        if ($unitKerjaId === null) {
            return ['baris' => collect(), 'ringkasan' => $this->absensi->ringkasanRekap(collect())];
        }

        $unitTeratas = UnitKerja::idTeratasUntuk($unitKerjaId) ?? $unitKerjaId;
        $cakupan = $this->cakupan($pelaku);

        $kunciSemua = [];

        for ($hari = $dari->copy(); $hari->lte($sampai); $hari->addDay()) {
            $kunciSemua[] = self::kunci($unitTeratas, $hari);
        }

        $sesiSemua = EventAbsen::query()->umum()->whereIn('kunci_sesi', $kunciSemua)->orderBy('tanggal')->get();

        $baris = $sesiSemua->flatMap(fn (EventAbsen $sesi) => $this->absensi->rekap($sesi, $cakupan)
            ->map(function (array $isi) use ($sesi) {
                $isi['tanggal'] = $sesi->tanggal->toDateString();
                $isi['tanggal_label'] = $sesi->tanggal->translatedFormat('d M');

                return $isi;
            }));

        $baris = $this->saring($baris, $cari);

        return [
            'baris' => $baris,
            'ringkasan' => $this->absensi->ringkasanRekap($baris),
        ];
    }

    /**
     * Cakupan unit pengguna, atau null bila tidak perlu disaring (FR-REK-02).
     *
     * @return array<int, int>|null
     */
    public function cakupan(User $pelaku): ?array
    {
        return $pelaku->lintasUnit()
            ? null
            : UnitKerja::idsDenganTurunan($pelaku->unit_kerja_id);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return Collection<int, array<string, mixed>>
     */
    protected function saring(Collection $baris, string $cari): Collection
    {
        $kunci = mb_strtolower(trim($cari));

        if ($kunci === '') {
            return $baris;
        }

        return $baris->filter(
            fn (array $isi) => str_contains(mb_strtolower($isi['nama']), $kunci)
                || str_contains((string) $isi['nip'], $kunci),
        );
    }

    /**
     * Ringkasan sesi, ditambah jumlah pegawai yang belum mencatat kehadiran.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return array<string, mixed>
     */
    protected function ringkasan(Collection $baris, ?int $unitKerjaId): array
    {
        $ringkasan = $this->absensi->ringkasanRekap($baris);

        $jumlahPegawai = $unitKerjaId === null ? 0 : Pegawai::query()
            ->where('aktif', true)
            ->whereIn('unit_kerja_id', UnitKerja::idsDenganTurunan($unitKerjaId))
            ->count();

        $ringkasan['pegawai'] = $jumlahPegawai;
        $ringkasan['belum_absen'] = max(0, $jumlahPegawai - $ringkasan['hadir']);

        return $ringkasan;
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

        // Jam masuk hari TANGGAL SESI, bukan "sekarang" — sesi bisa saja
        // dibuka untuk tanggal yang berbeda dari hari permintaan berjalan.
        $jamMasuk = $this->setting->jadwalUntukHari($tanggal->dayOfWeekIso)['jam_masuk'];

        $unit = UnitKerja::query()->find($unitKerjaId);
        $kunci = self::kunci($unitKerjaId, $tanggal);

        try {
            $sesi = EventAbsen::create([
                'nama' => 'Absen Umum'.($unit === null ? '' : " — {$unit->nama}"),
                'jenis' => JenisEvent::Umum,
                'kunci_sesi' => $kunci,
                'tanggal' => $tanggal->toDateString(),
                'jam_mulai' => $jamMasuk.':00',
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
