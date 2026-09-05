<?php

namespace App\Services;

use App\Enums\JenisAbsen;
use App\Enums\MetodeAbsen;
use App\Enums\StatusKetepatan;
use App\Exceptions\AbsenGandaException;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pencatatan kehadiran dari kiosk (FR-TAP-05, FR-TAP-07).
 *
 * Kiosk sudah memutuskan cocok/tidaknya wajah di sisi klien, tetapi keputusan
 * itu tidak dipercaya begitu saja: pemanggil tetap memvalidasi ulang event,
 * pegawai, dan ambang skor sebelum baris tersimpan (SDD §1.2).
 */
class AbsensiService
{
    /** Disk privat tempat foto absen disimpan (NFR-04). */
    public const string DISK = 'local';

    public const string DIREKTORI = 'foto-absen';

    /**
     * Batas ukuran foto yang diterima server.
     *
     * NFR-06 membatasi foto tersimpan pada ~90 KB dan preset terbesar berhenti
     * di 54 KB, jadi 150 KB memberi kelonggaran untuk selisih encoder peramban
     * sambil tetap menolak kiriman yang jelas di luar preset.
     */
    public const int BATAS_FOTO_BYTE = 150 * 1024;

    public function __construct(protected SettingAbsenService $setting) {}

    /**
     * Kehadiran yang sudah tercatat untuk satu pasangan event × pegawai ×
     * jenis, atau null bila belum ada.
     *
     * Dipakai layar titik absen untuk memberi tahu pegawai sebelum kamera
     * menyala — tidak ada gunanya memindai wajah orang yang jelas sudah
     * tercatat.
     */
    public function absenTercatat(
        EventAbsen $event,
        Pegawai $pegawai,
        JenisAbsen $jenis,
    ): ?Absensi {
        return Absensi::query()
            ->where('event_absen_id', $event->id)
            ->where('pegawai_id', $pegawai->id)
            ->where('jenis', $jenis)
            ->first();
    }

    /**
     * Catat kehadiran.
     *
     * FR-TAP-05 (revisi S28a): satu baris per (event, pegawai, jenis), dan tap
     * kedua untuk jenis yang sama DITOLAK, bukan menimpa yang pertama. Jam
     * kehadiran yang sudah tercatat adalah bukti; membiarkannya tergeser oleh
     * tap ulang berarti siapa pun dapat memindahkan jamnya sendiri hanya
     * dengan men-tap lagi.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AbsenGandaException bila kehadiran jenis itu sudah tercatat
     */
    public function catat(
        EventAbsen $event,
        Pegawai $pegawai,
        ?Kiosk $kiosk,
        array $data,
    ): Absensi {
        $jenis = JenisAbsen::from($data['jenis']);

        $tercatat = $this->absenTercatat($event, $pegawai, $jenis);

        if ($tercatat !== null) {
            throw new AbsenGandaException($tercatat);
        }

        $waktu = $this->waktuTap($event, $data['waktu_tap'] ?? null);
        $fotoPath = $this->simpanFoto($event, $pegawai, $jenis, $data['foto'] ?? null);

        try {
            return Absensi::create([
                'event_absen_id' => $event->id,
                'pegawai_id' => $pegawai->id,
                'jenis' => $jenis,
                'kiosk_id' => $kiosk?->id,
                'metode' => MetodeAbsen::from($data['metode']),
                'waktu' => $waktu,
                'status_ketepatan' => $this->ketepatan($event, $jenis, $waktu),
                'skor_kecocokan_wajah' => $data['skor'] ?? null,
                'foto_path' => $fotoPath,
            ]);
        } catch (UniqueConstraintViolationException) {
            /*
             * Dua perangkat men-tap orang yang sama dalam hitungan milidetik:
             * keduanya lolos pemeriksaan di atas, hanya satu yang menang kunci
             * unik. Yang kalah diperlakukan sama seperti tap kedua biasa —
             * ditolak, bukan digagalkan sebagai galat server.
             */
            if ($fotoPath !== null) {
                Storage::disk(self::DISK)->delete($fotoPath);
            }

            throw new AbsenGandaException(
                $this->absenTercatat($event, $pegawai, $jenis),
            );
        }
    }

    /**
     * Waktu yang dicatat untuk sebuah tap.
     *
     * Absen yang tertahan antrian luring baru sampai ke server beberapa menit
     * kemudian (NFR-05); mencatatnya pada jam pengiriman akan menggeser
     * kehadiran — dan bisa mengubah "tepat" menjadi "terlambat". Karena itu
     * kiosk menyertakan waktu tap yang sesungguhnya.
     *
     * Jam kiosk tidak dipercaya begitu saja, hanya diberi batas: waktu tap
     * ditolak bila berada di masa depan atau di luar hari penyelenggaraan
     * event, dan pada kedua keadaan itu server memakai jamnya sendiri.
     *
     * Peramban mengirimkan waktunya sebagai ISO-8601 berakhiran `Z` — yaitu
     * UTC. Carbon mempertahankan zona itu, dan Eloquent menyimpan jam dinding
     * instance apa adanya, sehingga tap pukul 09.00 WIB tersimpan 02:00 bila
     * zonanya tidak digeser lebih dahulu. Selisihnya persis sebesar offset WIB
     * dan tetap sepanjang hari, sehingga tampak "salah tetapi konsisten".
     *
     * Yang rusak adalah JAM YANG TERSIMPAN, dan ia terbawa ke mana-mana:
     * Daftar e-Presensi, Rekap Absen, laporan, dan seluruh ekspor. Penilaian
     * tepat/terlambat sendiri kebetulan selamat — Carbon membandingkan
     * instan, bukan jam dinding — sehingga barisnya tampil ganjil: "01:00,
     * Terlambat" terhadap batas 07.45. Justru itu yang membuatnya sukar
     * dilaporkan sebagai bug; yang terlihat pengguna hanya angka yang aneh.
     *
     * Ada satu efek kedua yang lebih licik. Pemeriksaan `isSameDay()` di bawah
     * membandingkan TANGGAL pada zona masing-masing, sehingga tap sebelum
     * pukul 07.00 WIB — yang di UTC masih tanggal kemarin — dianggap di luar
     * hari penyelenggaraan dan diam-diam diganti jam server. Sebagian absen
     * karena itu benar dan sebagian salah, pada satu hari yang sama.
     *
     * Gejalanya dikenali dengan membandingkan `absensi.waktu` terhadap
     * `created_at` pada baris yang sama: `created_at` memakai Carbon::now()
     * sehingga selalu benar, dan hanya `waktu` yang meleset.
     */
    protected function waktuTap(EventAbsen $event, ?string $waktuTap): Carbon
    {
        $sekarang = Carbon::now();

        if ($waktuTap === null) {
            return $sekarang;
        }

        try {
            $waktu = Carbon::parse($waktuTap)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return $sekarang;
        }

        // Selisih jam kecil antar perangkat wajar; jauh ke depan tidak.
        if ($waktu->greaterThan($sekarang->copy()->addMinutes(2))) {
            return $sekarang;
        }

        if (! $waktu->isSameDay($event->tanggal)) {
            return $sekarang;
        }

        return $waktu;
    }

    /**
     * FR-TAP-07: tepat waktu selama tap terjadi sebelum jam mulai ditambah
     * toleransi event. Absen pulang tidak mengenal keterlambatan.
     */
    public function ketepatan(EventAbsen $event, JenisAbsen $jenis, Carbon $waktu): ?StatusKetepatan
    {
        if ($jenis !== JenisAbsen::Datang) {
            return null;
        }

        $batas = $event->tanggal
            ->copy()
            ->setTimeFromTimeString((string) $event->jam_mulai)
            ->addMinutes($event->toleransi_menit);

        return $waktu->lessThanOrEqualTo($batas)
            ? StatusKetepatan::Tepat
            : StatusKetepatan::Terlambat;
    }

    /**
     * Daftar e-Presensi sebuah event: satu baris per pegawai, kolom Jam Masuk
     * dan Jam Pulang terisi dari dua jenis absen yang berbeda (UIUX §4.2.2).
     *
     * URL fotonya WAJIB dirakit pemanggil. Satu layar melayani beberapa titik
     * absen dengan pagar autentikasi berbeda — perangkat mode event, perangkat
     * mode umum, dan layar admin — sehingga bawaan yang mematok salah satu
     * grup rute akan menjawab 302 atau 403 pada dua grup lainnya, dan di layar
     * itu tampak sebagai ikon gambar rusak, bukan sebagai kegagalan yang
     * terbaca (pelajaran S28b). {@see TitikAbsenService::urlFotoAbsen()} yang
     * mengetahui pilihannya.
     *
     * @param  callable(int): string  $urlFoto  perakit URL foto absen
     * @return Collection<int, array<string, mixed>>
     */
    public function daftarPresensi(EventAbsen $event, callable $urlFoto): Collection
    {
        return Absensi::query()
            ->with('pegawai:id,nip,nama')
            ->where('event_absen_id', $event->id)
            ->orderBy('waktu')
            ->get()
            ->groupBy('pegawai_id')
            ->map(function (Collection $baris) use ($urlFoto) {
                $datang = $baris->firstWhere('jenis', JenisAbsen::Datang);
                $pulang = $baris->firstWhere('jenis', JenisAbsen::Pulang);
                $pegawai = $baris->first()->pegawai;

                return [
                    'pegawai_id' => $pegawai->id,
                    'nip' => $pegawai->nip,
                    'nama' => $pegawai->nama,
                    'jam_masuk' => $datang?->waktu->format('H:i'),
                    'jam_pulang' => $pulang?->waktu->format('H:i'),
                    'status_ketepatan' => $datang?->status_ketepatan?->value,
                    /*
                     * Nama rutenya datang dari pemanggil, bukan dipatok di sini.
                     * Layar yang sama dilayani dua pagar berbeda: perangkat absen
                     * lewat device token pada /kiosk, dan layar absen umum lewat
                     * sesi admin pada /admin/kelola-absen/absen-umum. URL yang
                     * dipatok ke salah satunya membuat gambar di sisi lain
                     * dijawab 302 ke layar aktivasi — ikon rusak, bukan foto.
                     */
                    'foto_url' => $datang?->foto_path === null
                        ? null
                        : $urlFoto($datang->id),
                    'urut' => $datang?->waktu ?? $baris->first()->waktu,
                ];
            })
            ->sortBy('urut')
            ->map(fn (array $baris) => collect($baris)->except('urut')->all())
            ->values();
    }

    /**
     * Rekap absen sebuah event untuk panel admin (FR-REK-01).
     *
     * Lebih lengkap daripada Daftar e-Presensi kiosk: membawa unit kerja,
     * metode, dan status ketepatan yang dibutuhkan rekap cetak.
     *
     * `$cakupanUnit` membatasi baris pada unit kerja tertentu (FR-REK-02).
     * Pembatasan itu berlaku pada **unit pegawai**, bukan cakupan event —
     * sehingga Admin UPT yang membuka event bercakupan "semua unit" tetap
     * hanya melihat pegawainya sendiri.
     *
     * @param  array<int, int>|null  $cakupanUnit
     * @return Collection<int, array<string, mixed>>
     */
    public function rekap(EventAbsen $event, ?array $cakupanUnit = null): Collection
    {
        return Absensi::query()
            ->with(['pegawai:id,nip,nama,unit_kerja_id', 'pegawai.unitKerja:id,kode,nama'])
            ->where('event_absen_id', $event->id)
            ->when($cakupanUnit !== null, fn ($q) => $q->whereHas(
                'pegawai',
                fn ($p) => $p->whereIn('unit_kerja_id', $cakupanUnit),
            ))
            ->orderBy('waktu')
            ->get()
            ->groupBy('pegawai_id')
            ->map(function (Collection $baris) {
                $datang = $baris->firstWhere('jenis', JenisAbsen::Datang);
                $pulang = $baris->firstWhere('jenis', JenisAbsen::Pulang);
                $pegawai = $baris->first()->pegawai;
                $rujukan = $datang ?? $baris->first();

                return [
                    'pegawai_id' => $pegawai->id,
                    'nip' => $pegawai->nip,
                    'nama' => $pegawai->nama,
                    'unit_kerja' => $pegawai->unitKerja?->nama,
                    'jam_masuk' => $datang?->waktu->format('H:i'),
                    'jam_pulang' => $pulang?->waktu->format('H:i'),
                    'metode' => $rujukan->metode->label(),
                    'status_ketepatan' => $datang?->status_ketepatan?->value,
                    'status_label' => $datang?->status_ketepatan?->label(),
                    'skor_kecocokan_wajah' => $datang?->skor_kecocokan_wajah,
                    'foto_url' => $datang?->foto_path === null
                        ? null
                        : route('absensi.foto', ['absensi' => $datang->id]),
                    'urut' => $rujukan->waktu,
                ];
            })
            ->sortBy('urut')
            ->map(fn (array $baris) => collect($baris)->except('urut')->all())
            ->values();
    }

    /**
     * Ringkasan angka sebuah rekap, untuk kepala halaman dan lembar cetak.
     *
     * @param  Collection<int, array<string, mixed>>  $rekap
     * @return array<string, int>
     */
    public function ringkasanRekap(Collection $rekap): array
    {
        return [
            'hadir' => $rekap->count(),
            'tepat' => $rekap->where('status_ketepatan', 'tepat')->count(),
            'terlambat' => $rekap->where('status_ketepatan', 'terlambat')->count(),
            'sudah_pulang' => $rekap->whereNotNull('jam_pulang')->count(),
        ];
    }

    /**
     * Simpan foto hasil capture yang dikirim kiosk sebagai data URI.
     *
     * Foto sudah disusutkan di kiosk sesuai preset Setting Absen; server hanya
     * memeriksa bentuk dan ukurannya, tidak menyusutkan ulang.
     */
    protected function simpanFoto(
        EventAbsen $event,
        Pegawai $pegawai,
        JenisAbsen $jenis,
        ?string $dataUri,
    ): ?string {
        $biner = self::binerDariDataUri($dataUri);

        if ($biner === null) {
            return null;
        }

        $path = sprintf(
            '%s/%d/%s-%s-%s.jpg',
            self::DIREKTORI,
            $event->id,
            $pegawai->nip,
            $jenis->value,
            Str::random(8),
        );

        Storage::disk(self::DISK)->put($path, $biner);

        return $path;
    }

    /**
     * Ubah data URI JPEG menjadi biner, atau null bila bentuknya tidak dikenali.
     */
    public static function binerDariDataUri(?string $dataUri): ?string
    {
        if ($dataUri === null || $dataUri === '') {
            return null;
        }

        if (! preg_match('#^data:image/jpe?g;base64,#i', $dataUri)) {
            return null;
        }

        $biner = base64_decode(substr($dataUri, strpos($dataUri, ',') + 1), strict: true);

        return $biner === false || $biner === '' ? null : $biner;
    }
}
