<?php

namespace App\Services;

use App\Enums\AksiLog;
use App\Enums\CakupanEvent;
use App\Enums\JenisAbsen;
use App\Enums\JenisEvent;
use App\Enums\OverrideAbsenUmum;
use App\Enums\StatusEvent;
use App\Models\EventAbsen;
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
 * {@see JenisEvent::Umum} yang dibuka sistem sendiri saat pertama kali
 * dibutuhkan.
 *
 * **Sejak S49 sesi itu SATU untuk seluruh dinas, bukan satu per unit kerja.**
 * Absen umum terbuka bagi setiap pegawai Dinas Tenaga Kerja dan Transmigrasi
 * tanpa kecuali, sehingga memecahnya per UPT hanya melahirkan pekerjaan yang
 * tidak dituntut siapa pun: admin harus membuka, menutup, dan meng-override
 * belasan sesi satu-satu, dan tiap tambahan unit menambah satu lagi yang bisa
 * terlewat. Yang tersisa dari unit kerja adalah perannya sebagai DIMENSI
 * PEMBACAAN — penyaring dan pengelompokan pada rekap dan laporan (FR-REK-02,
 * FR-LAP-02), bukan penentu siapa yang boleh mengabsen.
 *
 * Sakelar Absen Umum pada Setting Absen boleh dimatikan dan dinyalakan kembali
 * kapan saja tanpa memutus apa pun: yang dimatikannya adalah penerimaan tap,
 * bukan sesinya. Sesi hari itu tetap berdiri beserta seluruh absensi yang
 * sudah masuk, dan menyala kembali berarti melanjutkan — bukan memulai dari
 * nol (lihat {@see self::sesi()}).
 *
 * Sesi umum tidak pernah menghalangi kegiatan, dan tidak pernah didahului
 * kegiatan: keduanya dua layar terpisah pada perangkat absen, dipilih petugas
 * dari beranda (lihat {@see TitikAbsenService}). FR-EVT-06 pun hanya berlaku
 * antar event kegiatan — sesi harian tidak pernah ikut dihitung bentrok.
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
     * Sesi absen umum dinas pada satu tanggal.
     *
     * `$buat` sengaja tidak default true: layar dan pemantauan hanya membaca,
     * sehingga perangkat yang menyala sepanjang hari libur tidak meninggalkan
     * sesi kosong. Sesi baru lahir ketika benar-benar ada yang akan mengabsen.
     *
     * Sesi yang SUDAH ADA selalu dikembalikan, bahkan ketika sakelar Absen
     * Umum sedang dimatikan. Inilah yang membuat "matikan lalu nyalakan lagi"
     * melanjutkan proses absensi alih-alih memulainya dari awal: yang ditahan
     * sakelar itu hanya kelahiran sesi baru dan penerimaan tap
     * ({@see self::status()}), tidak pernah sesi yang sudah berjalan.
     */
    public function sesi(?Carbon $tanggal = null, bool $buat = false): ?EventAbsen
    {
        $tanggal ??= Carbon::today();

        $sesi = EventAbsen::query()
            ->umum()
            ->where('kunci_sesi', self::kunci($tanggal))
            ->first();

        if ($sesi !== null || ! $buat) {
            return $sesi;
        }

        return $this->aktif() ? $this->buatSesi($tanggal) : null;
    }

    /**
     * Penanda satu sesi harian: satu tanggal, satu dinas.
     *
     * Nilai ini yang menempati kolom unik `event_absen.kunci_sesi`, dan
     * keunikan kolom itulah yang menjamin dua perangkat yang men-tap bersamaan
     * tidak melahirkan dua sesi untuk hari yang sama.
     *
     * Bentuknya tetap berawalan `umum:` seperti sebelum S49, hanya tanpa
     * segmen unit kerja — migration penggabungan menulis ulang kunci sesi lama
     * ke bentuk ini.
     */
    public static function kunci(Carbon $tanggal): string
    {
        return 'umum:'.$tanggal->toDateString();
    }

    /**
     * Unit kerja yang mewakili "dinas" pada pertanyaan kalender.
     *
     * Jendela Absen Umum kini satu untuk semuanya, sehingga hari kerja dan
     * hari liburnya pun dibaca pada tingkat dinas: simpul OPD. Hari libur
     * khusus sebuah UPT tidak lagi menutup jendela bagi UPT itu sendiri — ia
     * tetap tercatat sebagai penanda `Absensi.hari_libur` pada tap pegawainya
     * ({@see AbsensiService::catat()}), yang memakai unit pegawainya masing-
     * masing, dan di sanalah pembedaan itu memang berarti.
     *
     * Null pada instalasi yang belum pernah menyinkronkan WORKA; kalender
     * menjawabnya dengan hari libur nasional dan hari kerja bawaan.
     */
    public function unitDinas(): ?int
    {
        return UnitKerja::idOpd();
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
     *   3. Bukan hari kerja dinas (akhir pekan atau hari libur terdaftar) →
     *      tertutup otomatis. Revisi kebijakan S39: sebelum itu, hari libur
     *      hanya menandai tanpa menutup, dan satu-satunya yang membuka
     *      jendelanya kembali adalah override di langkah 2 — sengaja
     *      diperiksa lebih dahulu supaya petugas piket yang memang ditugaskan
     *      tetap bisa dibukakan.
     *   4. Selebihnya → di dalam jendela jam bawaan berarti terbuka.
     */
    public function status(
        JenisAbsen $jenis,
        ?EventAbsen $sesi = null,
        ?Carbon $waktu = null,
    ): StatusAbsenUmum {
        $setting = $this->setting->ambil();
        $waktu ??= Carbon::now();

        $alasanLibur = $this->kalender->alasanLibur($this->unitDinas(), $waktu->copy()->startOfDay());

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
    public function statusSemua(?EventAbsen $sesi = null, ?Carbon $waktu = null): array
    {
        return [
            JenisAbsen::Datang->value => $this->status(JenisAbsen::Datang, $sesi, $waktu),
            JenisAbsen::Pulang->value => $this->status(JenisAbsen::Pulang, $sesi, $waktu),
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
    public function aturOverride(?OverrideAbsenUmum $override, User $pelaku, ?Carbon $tanggal = null): ?EventAbsen
    {
        $sesi = $this->sesi($tanggal, buat: true);

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
     * `$unitKerjaId` adalah PENYARING TAMPILAN, bukan pembatas hak: sejak sesi
     * menjadi satu untuk seluruh dinas, unit kerja hanya berguna untuk
     * mempersempit apa yang sedang dibaca admin. Pembatasan hak yang
     * sesungguhnya tetap datang dari peran ({@see self::cakupan()}, FR-REK-02).
     *
     * @return array{sesi: ?EventAbsen, baris: Collection<int, array<string, mixed>>, ringkasan: array<string, mixed>}
     */
    public function rekapHarian(
        User $pelaku,
        ?Carbon $tanggal = null,
        string $cari = '',
        ?int $unitKerjaId = null,
    ): array {
        $sesi = $this->sesi($tanggal);
        $cakupan = $this->cakupanTampilan($pelaku, $unitKerjaId);

        $baris = $sesi === null
            ? collect()
            : $this->saring($this->absensi->rekap($sesi, $cakupan), $cari);

        return [
            'sesi' => $sesi,
            'baris' => $baris,
            'ringkasan' => $this->ringkasan($baris, $cakupan),
        ];
    }

    /**
     * Rekap Absen Umum lintas BEBERAPA hari sekaligus (Bagian 4, revisi
     * rentang tanggal) — satu baris per sesi harian yang memang ada dalam
     * rentangnya. `tanggal`/`tanggal_label` ditambahkan pada tiap baris supaya
     * pegawai yang sama pada hari berbeda tetap terbedakan di tabel;
     * TabelRekap.vue menampilkan kolom itu hanya ketika rentangnya lebih dari
     * satu hari.
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
        Carbon $dari,
        Carbon $sampai,
        string $cari = '',
        ?int $unitKerjaId = null,
    ): array {
        $cakupan = $this->cakupanTampilan($pelaku, $unitKerjaId);
        $kunciSemua = [];

        for ($hari = $dari->copy(); $hari->lte($sampai); $hari->addDay()) {
            $kunciSemua[] = self::kunci($hari);
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
     * Cakupan yang benar-benar dipakai menyaring baris: hak peran DIPOTONG
     * penyaring tampilan, tidak pernah diperluas olehnya.
     *
     * Admin UPT yang menyaring unit lain karena itu tidak melihat apa pun,
     * bukan melihat unit yang bukan haknya — irisan kosong adalah jawaban yang
     * benar, dan lebih aman daripada mengabaikan penyaringnya.
     *
     * @return array<int, int>|null
     */
    public function cakupanTampilan(User $pelaku, ?int $unitKerjaId): ?array
    {
        $peran = $this->cakupan($pelaku);

        if ($unitKerjaId === null) {
            return $peran;
        }

        $disaring = UnitKerja::idsDenganTurunan($unitKerjaId);

        return $peran === null ? $disaring : array_values(array_intersect($peran, $disaring));
    }

    /**
     * Unit kerja yang dapat dipilih sebagai penyaring rekap absen umum.
     *
     * Admin UPT hanya melihat unit yang menaunginya — menawarkan unit lain
     * pada penyaring hanya akan menghasilkan tabel kosong yang tampak rusak.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function unitTersedia(User $pelaku): Collection
    {
        return UnitKerja::query()
            ->levelTeratas()
            ->aktif()
            ->when(
                ! $pelaku->lintasUnit(),
                fn ($q) => $q->whereIn('id', UnitKerja::idTeratasMenaungi($pelaku->unit_kerja_id)),
            )
            ->orderBy('nama')
            ->get(['id', 'kode', 'nama'])
            ->map(fn (UnitKerja $unit) => $unit->only(['id', 'kode', 'nama']))
            ->values();
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
     * Penyebutnya mengikuti cakupan yang sedang dibaca: tanpa penyaring ia
     * seluruh pegawai aktif dinas, dengan penyaring ia pegawai aktif pada
     * cakupan itu saja. Menghitungnya selalu atas seluruh dinas akan membuat
     * "belum absen" pada tampilan tersaring tampak jauh lebih buruk daripada
     * keadaan sebenarnya.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     * @param  array<int, int>|null  $cakupan
     * @return array<string, mixed>
     */
    protected function ringkasan(Collection $baris, ?array $cakupan): array
    {
        $ringkasan = $this->absensi->ringkasanRekap($baris);

        $jumlahPegawai = Pegawai::query()
            ->where('aktif', true)
            ->when($cakupan !== null, fn ($q) => $q->whereIn('unit_kerja_id', $cakupan))
            ->count();

        $ringkasan['pegawai'] = $jumlahPegawai;
        $ringkasan['belum_absen'] = max(0, $jumlahPegawai - $ringkasan['hadir']);

        return $ringkasan;
    }

    /**
     * Riwayat sesi absen umum, terbaru lebih dahulu.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function riwayat(int $batas = 14): Collection
    {
        return EventAbsen::query()
            ->umum()
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
    public function buka(?Carbon $tanggal = null): ?EventAbsen
    {
        return $this->sesi($tanggal, buat: true);
    }

    /**
     * Sesi baru untuk satu tanggal.
     *
     * Jam masuk dan toleransi disalin dari Setting Absen saat sesi dibuat,
     * mengikuti perlakuan yang sama pada event kegiatan (FR-SET-02): menggeser
     * setting global tidak boleh mengubah penilaian tepat/terlambat sesi yang
     * sudah berjalan.
     */
    protected function buatSesi(Carbon $tanggal): EventAbsen
    {
        $setting = $this->setting->ambil();

        // Jam masuk hari TANGGAL SESI, bukan "sekarang" — sesi bisa saja
        // dibuka untuk tanggal yang berbeda dari hari permintaan berjalan.
        $jamMasuk = $this->setting->jadwalUntukHari($tanggal->dayOfWeekIso)['jam_masuk'];

        $kunci = self::kunci($tanggal);

        try {
            return EventAbsen::create([
                'nama' => 'Absen Umum — '.$tanggal->translatedFormat('d F Y'),
                'jenis' => JenisEvent::Umum,
                'kunci_sesi' => $kunci,
                'tanggal' => $tanggal->toDateString(),
                'jam_mulai' => $jamMasuk.':00',
                'toleransi_menit' => $setting['toleransi_default_menit'],

                // Sesi harian berlaku bagi seluruh dinas, sama seperti event
                // kegiatan sejak S49; tidak ada baris pivot unit kerja.
                'cakupan' => CakupanEvent::SemuaUnit,
                'status' => StatusEvent::Aktif,

                // Tidak ada pembuat: sesi ini dibuka sistem, bukan seorang admin.
                'dibuat_oleh' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            /*
             * Titik absen lain membuka sesi hari ini lebih dahulu, terpaut
             * milidetik. Tanpa kunci unik, keduanya akan lahir dan tap
             * berikutnya jatuh ke salah satunya secara tak tentu — membuat
             * penolakan tap ganda (FR-TAP-05) tidak pernah kena.
             */
            return EventAbsen::query()
                ->umum()
                ->where('kunci_sesi', $kunci)
                ->firstOrFail();
        }
    }
}
