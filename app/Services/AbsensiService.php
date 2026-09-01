<?php

namespace App\Services;

use App\Enums\JenisAbsen;
use App\Enums\MetodeAbsen;
use App\Enums\StatusKetepatan;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
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
     * Catat kehadiran, atau perbarui bila jenis yang sama sudah tercatat.
     *
     * @param  array<string, mixed>  $data
     */
    public function catat(
        EventAbsen $event,
        Pegawai $pegawai,
        ?Kiosk $kiosk,
        array $data,
    ): Absensi {
        $jenis = JenisAbsen::from($data['jenis']);
        $waktu = Carbon::now();

        $sebelumnya = Absensi::query()
            ->where('event_absen_id', $event->id)
            ->where('pegawai_id', $pegawai->id)
            ->where('jenis', $jenis)
            ->first();

        $fotoPath = $this->simpanFoto($event, $pegawai, $jenis, $data['foto'] ?? null)
            ?? $sebelumnya?->foto_path;

        $atribut = [
            'kiosk_id' => $kiosk?->id,
            'metode' => MetodeAbsen::from($data['metode']),
            'waktu' => $waktu,
            'status_ketepatan' => $this->ketepatan($event, $jenis, $waktu),
            'skor_kecocokan_wajah' => $data['skor'] ?? null,
            'foto_path' => $fotoPath,
        ];

        /*
         * FR-TAP-05: satu baris per (event, pegawai, jenis). Tap berulang
         * memperbarui baris yang sama sehingga Daftar e-Presensi tidak
         * menumbuhkan baris duplikat.
         */
        $absensi = Absensi::updateOrCreate(
            [
                'event_absen_id' => $event->id,
                'pegawai_id' => $pegawai->id,
                'jenis' => $jenis,
            ],
            $atribut,
        );

        // Foto lama dibuang setelah baris tersimpan, dan hanya bila benar-benar
        // digantikan berkas baru.
        if ($sebelumnya?->foto_path !== null && $sebelumnya->foto_path !== $fotoPath) {
            Storage::disk(self::DISK)->delete($sebelumnya->foto_path);
        }

        return $absensi;
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
     * @return Collection<int, array<string, mixed>>
     */
    public function daftarPresensi(EventAbsen $event): Collection
    {
        return Absensi::query()
            ->with('pegawai:id,nip,nama')
            ->where('event_absen_id', $event->id)
            ->orderBy('waktu')
            ->get()
            ->groupBy('pegawai_id')
            ->map(function (Collection $baris) {
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
                    'foto_url' => $datang?->foto_path === null
                        ? null
                        : route('kiosk.absen.foto', ['absensi' => $datang->id]),
                    'urut' => $datang?->waktu ?? $baris->first()->waktu,
                ];
            })
            ->sortBy('urut')
            ->map(fn (array $baris) => collect($baris)->except('urut')->all())
            ->values();
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
