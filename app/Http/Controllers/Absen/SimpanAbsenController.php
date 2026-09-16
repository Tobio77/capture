<?php

namespace App\Http\Controllers\Absen;

use App\Enums\JenisAbsen;
use App\Enums\SumberKiosk;
use App\Exceptions\AbsenGandaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanAbsenRequest;
use App\Services\AbsensiService;
use App\Services\AbsenUmumService;
use App\Services\EventAbsenService;
use App\Services\FotoReferensiWajahService;
use App\Services\KartuRfidService;
use App\Services\SettingAbsenService;
use App\Services\TitikAbsenService;
use Illuminate\Http\JsonResponse;

/**
 * Penyimpanan hasil absen dari kiosk (FR-TAP-05 s.d. FR-TAP-07).
 *
 * Kiosk sudah memutuskan cocok/tidaknya wajah di sisi klien, tetapi keputusan
 * itu datang dari peramban yang dapat dimanipulasi. Karena itu seluruh syarat
 * diperiksa ulang di sini sebelum baris tersimpan (SDD §1.2): event masih
 * dibuka dan mencakup kiosk, pegawai dikenal dan aktif, dan skor kecocokan
 * benar-benar melampaui ambang Setting Absen.
 */
class SimpanAbsenController extends Controller
{
    public function __construct(
        protected AbsensiService $absensi,
        protected EventAbsenService $event,
        protected KartuRfidService $kartu,
        protected SettingAbsenService $setting,
        protected TitikAbsenService $titik,
        protected FotoReferensiWajahService $wajah,
        protected AbsenUmumService $absenUmum,
    ) {}

    public function __invoke(SimpanAbsenRequest $request): JsonResponse
    {
        ['event' => $event, 'kiosk' => $kiosk] = $this->titik->untuk($request, buka: true);

        // FR-EVT-04: entry yang sudah ditutup menolak tap baru.
        if ($event === null) {
            return $this->gagal('EVENT_TIDAK_AKTIF', $this->titik->pesanTidakAda($request), 409);
        }

        /*
         * FR-SET-07: sesi absen umum punya jendela jam per jenis. Diperiksa di
         * sini, bukan hanya disembunyikan di layar — layar dapat dimuat pukul
         * 08.55 lalu di-tap pukul 09.05, dan perangkat yang menyala semalaman
         * memegang layar dari jendela kemarin.
         *
         * Kegiatan tidak mengenal jendela: yang membuka dan menutupnya adalah
         * status entry (FR-EVT-04).
         */
        if ($event->absenUmum()) {
            $status = $this->absenUmum->status(
                JenisAbsen::from($request->string('jenis')->toString()),
                $event,
            );

            if (! $status->terbuka) {
                return $this->gagal('DI_LUAR_JAM', $status->keterangan(), 409);
            }
        }

        $pegawai = $this->kartu->kenali($request->string('id_card')->toString());

        if ($pegawai === null) {
            return $this->gagal('ID_TIDAK_DIKENAL', 'Kartu atau NIP tidak terdaftar dalam sistem.', 404);
        }

        if (! $pegawai->aktif) {
            return $this->gagal('PEGAWAI_TIDAK_AKTIF', 'Pegawai tidak aktif.', 403);
        }

        /*
         * Pegawai harus termasuk cakupan event yang sedang dilayani titik absen
         * ini (perbaikan H-1).
         *
         * Sebelumnya tidak ada pemeriksaan apa pun: `kenali()` mencari ke
         * SELURUH tabel pegawai, sehingga perangkat di satu UPT dapat
         * mencatatkan kehadiran pegawai UPT lain pada eventnya sendiri. Pagar
         * yang sama sudah lama berdiri di FotoPegawaiController — ia ada di
         * endpoint foto, tetapi tidak di endpoint yang menulis absensi.
         */
        /*
         * Sejak S49 cakupan setiap event adalah SELURUH dinas, sehingga
         * pemeriksaan ini tidak pernah lagi menolak siapa pun — dan memang
         * begitu yang dikehendaki: absen umum maupun kegiatan terbuka untuk
         * pegawai unit mana pun. Pagarnya sengaja dibiarkan berdiri alih-alih
         * dibuang, sebab cakupan tetap dijawab satu tempat
         * ({@see EventAbsenService::unitTercakup()}) dan di sanalah keputusan
         * ini akan berubah kembali bila kelak dibutuhkan.
         */
        if (! in_array($pegawai->unit_kerja_id, $this->event->unitTercakup($event), true)) {
            return $this->gagal(
                'DI_LUAR_CAKUPAN',
                'Pegawai ini tidak termasuk cakupan kegiatan pada titik absen ini.',
                403,
            );
        }

        $setting = $this->setting->ambil();
        $skor = null;

        /*
         * FR-TAP-06: kehadiran hanya dicatat bila verifikasi wajah berhasil.
         *
         * Yang memutuskan adalah SERVER, bukan medan `skor` kiriman peramban
         * (perbaikan C-1). Deskriptor hasil capture dibandingkan di sini dengan
         * embedding referensi yang tidak pernah meninggalkan server; angka apa
         * pun yang disertakan peramban pada medan `skor` diabaikan.
         */
        if ($setting['metode_wajah_aktif']) {
            $hasil = FotoReferensiWajahService::cocokkan(
                $pegawai,
                $request->input('embedding'),
                (float) $setting['ambang_kecocokan_wajah'],
            );

            if ($hasil['skor'] === null) {
                return $this->gagal('WAJAH_BELUM_DIVERIFIKASI', $hasil['alasan'], 422);
            }

            if (! $hasil['cocok']) {
                return $this->gagal(
                    'WAJAH_TIDAK_COCOK',
                    "Skor kecocokan {$hasil['skor']}% di bawah ambang {$setting['ambang_kecocokan_wajah']}%.",
                    422,
                    ['skor' => $hasil['skor']],
                );
            }

            $skor = $hasil['skor'];
        }

        try {
            $absensi = $this->absensi->catat($event, $pegawai, $kiosk, [
                'jenis' => $request->string('jenis')->toString(),
                'metode' => $request->string('metode')->toString(),
                'skor' => $setting['metode_wajah_aktif'] ? $skor : null,

                /*
                 * Foto tetap disimpan walau verifikasi wajah dimatikan: ia
                 * berfungsi sebagai bukti kehadiran, bukan hanya bahan
                 * pencocokan (revisi FR-SET-01, S28a).
                 */
                'foto' => $request->input('foto'),

                // Diisi kiosk agar absen yang tertahan antrian luring tetap
                // tercatat pada jam tapnya, bukan jam pengirimannya (NFR-05).
                'waktu_tap' => $request->input('waktu_tap'),
            ],
                /*
                 * Alamat perangkat pengirim, disimpan pada baris absensinya
                 * sendiri (S49). Diambil dari permintaan, bukan dari kolom
                 * `kiosk.ip_terakhir`: yang dicari rekap adalah alamat saat
                 * tap ini terjadi, dan kolom itu bergerak mengikuti keadaan
                 * terkini perangkat.
                 */
                ip: $kiosk === null ? null : $request->ip(),
            );
        } catch (AbsenGandaException $ganda) {
            /*
             * FR-TAP-05 (revisi S28a): tap kedua untuk jenis yang sama ditolak.
             * Daftar presensi tetap dikirim supaya layar titik absen menampilkan
             * keadaan terkini, bukan berhenti pada tampilan lama.
             */
            return response()->json([
                'success' => false,
                'code' => 'SUDAH_ABSEN',
                'message' => $ganda->pesan(),
                'data' => [
                    'jenis' => $ganda->tercatat->jenis->value,
                    'waktu' => $ganda->tercatat->waktu->format('H:i'),
                    'daftar_presensi' => $this->absensi->daftarPresensi(
                        $event,
                        fn (int $id) => $this->titik->urlFotoAbsen($request, $id),
                    ),
                ],
            ], 409);
        }

        /*
         * FR-PEG-05 (revisi S29). Pendaftaran wajah massal tidak pernah selesai
         * serentak; selama verifikasi wajah dimatikan, pegawai yang belum
         * pernah difoto admin tetap mengabsen dengan kamera menyala. Foto itu
         * dipromosikan menjadi foto referensinya — tetapi hanya bila lolos
         * pemeriksaan kualitas yang sama dengan pendaftaran manual.
         *
         * Hanya berlaku saat verifikasi wajah MATI. Ketika ia menyala, absen
         * pegawai tanpa foto referensi sudah ditolak jauh sebelum baris ini,
         * dan tidak ada foto yang boleh dipromosikan tanpa pembanding.
         *
         * TIDAK PERNAH dari perangkat ad-hoc (perbaikan H-2).
         *
         * Perangkat ad-hoc lahir dari Mode Terbuka: siapa pun yang dapat
         * menjangkau alamat aplikasi menerbitkan device token untuk dirinya
         * sendiri, tanpa seorang pun meninjaunya. Membiarkannya memasok foto
         * referensi berarti penyerang dapat menjadikan wajahnya sendiri sebagai
         * wajah resmi pegawai lain — dan kerusakan itu BERTAHAN melewati
         * penyalaan kembali verifikasi wajah, karena justru pada hari itulah
         * wajah palsunya mulai dipakai mencocokkan.
         *
         * Layar absen umum di peramban admin ($kiosk === null) tetap boleh:
         * di baliknya ada sesi admin yang namanya tercatat, bukan mesin
         * anonim.
         */
        $bolehPromosi = $kiosk === null || $kiosk->sumber !== SumberKiosk::AdHoc;

        $dipromosikan = ! $setting['metode_wajah_aktif']
            && $bolehPromosi
            && $this->wajah->promosikanDariAbsen(
                $pegawai,
                $absensi->foto_path,
                $request->input('embedding'),
            );

        return response()->json([
            'success' => true,
            'data' => [
                'jenis' => $absensi->jenis->value,
                'waktu' => $absensi->waktu->format('H:i'),
                'status_ketepatan' => $absensi->status_ketepatan?->value,

                // Skor hasil perhitungan SERVER, bukan angka kiriman layar —
                // layar menampilkannya, tidak lagi menentukannya.
                'skor' => $absensi->skor_kecocokan_wajah,

                // Layar memberitahukannya kepada pegawai: fotonya kini menjadi
                // foto referensi, dan ia tidak perlu mendatangi admin lagi.
                'wajah_didaftarkan' => $dipromosikan,
                'daftar_presensi' => $this->absensi->daftarPresensi(
                    $event,
                    fn (int $id) => $this->titik->urlFotoAbsen($request, $id),
                ),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  keterangan tambahan untuk layar, mis. skor kecocokan
     */
    protected function gagal(string $kode, string $pesan, int $status, array $data = []): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'code' => $kode,
            'message' => $pesan,
            'data' => $data === [] ? null : $data,
        ], fn ($nilai) => $nilai !== null), $status);
    }
}
