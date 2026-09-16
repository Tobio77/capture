<?php

namespace App\Services;

use App\Enums\JenisAbsen;
use App\Enums\StatusKetepatan;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan kehadiran per pegawai (FR-LAP-01 s.d. FR-LAP-03).
 *
 * "Tanpa keterangan" hanya bermakna bila diketahui event mana yang berlaku
 * bagi seorang pegawai. Karena itu laporan ini tidak sekadar menghitung baris
 * absensi: ia lebih dulu menentukan, untuk setiap event dalam rentang, unit
 * kerja mana saja yang tercakup — lalu membandingkannya dengan kehadiran yang
 * benar-benar tercatat.
 */
class LaporanService
{
    /** Jumlah baris per halaman pada layar laporan. */
    public const int PER_HALAMAN = 25;

    /**
     * Satu halaman laporan.
     *
     * Ekspor sengaja tidak memakai ini melainkan {@see self::rekap()} penuh:
     * berkas unduhan harus memuat seluruh pegawai, bukan halaman yang kebetulan
     * sedang dibuka.
     *
     * @return array<string, mixed>
     */
    public function halaman(
        User $pelaku,
        Carbon $dari,
        Carbon $sampai,
        ?int $unitKerjaId,
        ?string $cari,
        int $halaman,
    ): array {
        $hasil = $this->rekap($pelaku, $dari, $sampai, $unitKerjaId);

        $baris = $this->saring($hasil['baris'], $cari);

        return [
            'baris' => new LengthAwarePaginator(
                $baris->forPage($halaman, self::PER_HALAMAN)->values(),
                $baris->count(),
                self::PER_HALAMAN,
                $halaman,
                ['path' => Paginator::resolveCurrentPath()],
            ),

            // Ringkasan mengikuti hasil penyaringan, bukan halaman: yang ingin
            // diketahui adalah total untuk pilihan yang sedang dilihat.
            'ringkasan' => $this->ringkasan($baris),
            'jumlah_event' => $hasil['jumlah_event'],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return Collection<int, array<string, mixed>>
     */
    public function saring(Collection $baris, ?string $cari): Collection
    {
        if (blank($cari)) {
            return $baris;
        }

        $kunci = mb_strtolower(trim($cari));

        return $baris
            ->filter(fn (array $isi) => str_contains(mb_strtolower($isi['nama']), $kunci)
                || str_contains($isi['nip'], $kunci)
                || str_contains(mb_strtolower($isi['unit_kerja'] ?? ''), $kunci))
            ->values();
    }

    /**
     * Rekap kehadiran per pegawai pada rentang tanggal tertentu.
     *
     * @return array<string, mixed>
     */
    public function rekap(User $pelaku, Carbon $dari, Carbon $sampai, ?int $unitKerjaId = null): array
    {
        $cakupanPengguna = $this->cakupanPengguna($pelaku);
        $cakupanTampilan = $this->cakupanTampilan($cakupanPengguna, $unitKerjaId);

        $event = $this->eventPadaRentang($dari, $sampai);
        $pegawai = $this->pegawaiTerpilih($cakupanTampilan);

        if ($pegawai->isEmpty()) {
            return ['baris' => collect(), 'ringkasan' => $this->ringkasan(collect()), 'jumlah_event' => $event->count()];
        }

        $berlakuUntukUnit = $this->eventPerUnit($event);
        $kehadiran = $this->kehadiran($event->pluck('id'), $pegawai->pluck('id'));

        /*
         * Kolom "Unit Kerja" menyebut unit LEVEL TERATAS, bukan seksi tempat
         * pegawai bertaut. Pegawai menaut ke seksi/subbag, sedangkan yang
         * dikenali pembaca laporan — dan yang ditawarkan penyaring di atas
         * tabel — adalah bidang atau UPT. Tabel yang penyaringnya menyebut
         * "Bidang Hubungan Industrial dan Jaminan Sosial" lalu memuat kolom
         * berisi "Kelompok Jabatan Fungsional dan Pelaksana" membuat pembaca
         * mengira penyaringnya tidak bekerja.
         *
         * Yang berubah HANYA nama yang tertulis. Perhitungan di bawah tetap
         * memakai `$orang->unit_kerja_id` yang sebenarnya — cakupan event
         * dinyatakan per unit dan sudah diperluas ke seluruh turunan pada
         * eventPerUnit(). Menggulung id-nya di sini, bukan sekadar namanya,
         * akan membuat laporan tampak rapi sambil salah menghitung.
         */
        $namaTeratas = UnitKerja::namaTeratasPerUnit();

        $baris = $pegawai
            ->map(function (Pegawai $orang) use ($berlakuUntukUnit, $kehadiran, $namaTeratas) {
                $berlaku = $berlakuUntukUnit[$orang->unit_kerja_id] ?? 0;
                $catatan = $kehadiran[$orang->id] ?? ['hadir' => 0, 'terlambat' => 0];

                return [
                    'pegawai_id' => $orang->id,
                    'nip' => $orang->nip,
                    'nama' => $orang->nama,
                    'unit_kerja' => $namaTeratas[$orang->unit_kerja_id] ?? $orang->unitKerja?->nama,
                    'event_berlaku' => $berlaku,
                    'hadir' => $catatan['hadir'],
                    'terlambat' => $catatan['terlambat'],

                    /*
                     * Event yang berlaku baginya tetapi tidak menghasilkan
                     * catatan kehadiran. Tidak pernah negatif: kehadiran
                     * selalu berasal dari event yang berlaku juga.
                     */
                    'tanpa_keterangan' => max(0, $berlaku - $catatan['hadir']),
                ];
            })
            ->sortBy('nama')
            ->values();

        return [
            'baris' => $baris,
            'ringkasan' => $this->ringkasan($baris),
            'jumlah_event' => $event->count(),
        ];
    }

    /**
     * Rincian kehadiran: satu baris per pegawai PER SESI ABSEN, lengkap dengan
     * jam masuk dan jam pulang yang benar-benar tercatat (FR-LAP-03).
     *
     * Grainnya berbeda dari {@see self::rekap()}, dan itulah alasannya ada.
     * Rekap menjawab "berapa kali" — satu baris per pegawai untuk seluruh
     * rentang, berisi hitungan. Pertanyaan yang justru dibawa orang ke laporan
     * kehadiran adalah "pukul berapa ia datang dan pulang", dan hitungan tidak
     * pernah dapat menjawabnya: seorang pegawai punya sepasang jam untuk
     * SETIAP sesi yang dihadirinya.
     *
     * Grainnya per SESI, bukan per tanggal. Satu hari dapat memuat apel pagi
     * dan absen umum sekaligus, dan menggabungkan keduanya ke satu baris akan
     * membuang salah satu pasang jam. Kolom `kegiatan` yang membedakannya.
     *
     * Pegawai yang tidak hadir sama sekali tidak muncul di sini — rincian ini
     * memuat yang TERCATAT; angka ketidakhadiran tetap dibaca dari rekap.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rincian(User $pelaku, Carbon $dari, Carbon $sampai, ?int $unitKerjaId = null): Collection
    {
        $cakupan = $this->cakupanTampilan($this->cakupanPengguna($pelaku), $unitKerjaId);
        $event = $this->eventPadaRentang($dari, $sampai);

        if ($event->isEmpty()) {
            return collect();
        }

        $namaTeratas = UnitKerja::namaTeratasPerUnit();

        return Absensi::query()
            ->with(['pegawai:id,nip,nama,unit_kerja_id', 'kiosk:id,nama_titik'])
            ->whereIn('event_absen_id', $event->pluck('id'))
            ->whereHas('pegawai', fn ($p) => $p->whereIn('unit_kerja_id', $cakupan))
            ->orderBy('waktu')
            ->get()

            /*
             * Datang dan pulang adalah DUA baris `absensi` pada sesi yang
             * sama; keduanya dilipat menjadi satu baris rincian, persis
             * seperti yang dilakukan Rekap Absen.
             */
            ->groupBy(fn (Absensi $satu) => $satu->pegawai_id.'|'.$satu->event_absen_id)
            ->map(function (Collection $baris) use ($event, $namaTeratas) {
                $datang = $baris->firstWhere('jenis', JenisAbsen::Datang);
                $pulang = $baris->firstWhere('jenis', JenisAbsen::Pulang);
                $rujukan = $datang ?? $baris->first();
                $orang = $rujukan->pegawai;
                $sesi = $event->firstWhere('id', $rujukan->event_absen_id);

                return [
                    'pegawai_id' => $orang->id,
                    'nip' => $orang->nip,
                    'nama' => $orang->nama,
                    'unit_kerja' => $namaTeratas[$orang->unit_kerja_id] ?? $orang->unitKerja?->nama,
                    'tanggal' => $sesi?->tanggal->toDateString(),
                    'tanggal_label' => $sesi?->tanggal->format('d-m-Y'),
                    'kegiatan' => $sesi?->nama,
                    'jam_masuk' => $datang?->waktu->format('H:i'),
                    'jam_pulang' => $pulang?->waktu->format('H:i'),
                    'status_label' => $datang?->status_ketepatan?->label(),
                    'metode' => $rujukan->metode->label(),
                    'perangkat' => $rujukan->kiosk?->nama_titik,
                    'ip_address' => $rujukan->ip_address,
                ];
            })

            // Berkas administratif dibaca per orang, bukan per jam: nama lebih
            // dahulu, tanggal menyusul di dalamnya.
            ->sortBy([['nama', 'asc'], ['tanggal', 'asc'], ['kegiatan', 'asc']])
            ->values();
    }

    /**
     * Unit kerja yang boleh dipilih sebagai penyaring laporan.
     *
     * @return Collection<int, UnitKerja>
     */
    public function unitKerjaTersedia(User $pelaku): Collection
    {
        $teratas = UnitKerja::query()->levelTeratas()->orderBy('nama')->get(['id', 'kode', 'nama']);

        if ($pelaku->lintasUnit()) {
            return $teratas;
        }

        return $teratas
            ->filter(fn (UnitKerja $unit) => in_array(
                $pelaku->unit_kerja_id,
                UnitKerja::idsDenganTurunan($unit->id),
                true,
            ))
            ->values();
    }

    /**
     * Baris laporan sebagai CSV, siap dibuka Excel (FR-LAP-03).
     *
     * Pemisahnya titik koma, bukan koma: Excel berlokal Indonesia membaca
     * koma sebagai pemisah desimal dan akan menggabungkan seluruh kolom
     * menjadi satu. BOM UTF-8 disertakan supaya nama ber-diakritik tidak
     * rusak saat dibuka.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     */
    public function csv(Collection $baris): string
    {
        $judul = ['NIP', 'Nama', 'Unit Kerja', 'Event Berlaku', 'Hadir', 'Terlambat', 'Tanpa Keterangan'];

        $garis = [$this->barisCsv($judul)];

        foreach ($baris as $isi) {
            $garis[] = $this->barisCsv([
                $isi['nip'],
                $isi['nama'],
                $isi['unit_kerja'] ?? '',
                $isi['event_berlaku'],
                $isi['hadir'],
                $isi['terlambat'],
                $isi['tanpa_keterangan'],
            ]);
        }

        return "\u{FEFF}".implode("\r\n", $garis)."\r\n";
    }

    /**
     * @param  array<int, mixed>  $kolom
     */
    protected function barisCsv(array $kolom): string
    {
        return implode(';', array_map(
            // NIP diawali apostrof agar Excel tidak memperlakukannya sebagai
            // bilangan dan membuang angka nol di depan.
            fn ($nilai) => '"'.str_replace('"', '""', (string) $nilai).'"',
            $kolom,
        ));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return array<string, int>
     */
    public function ringkasanUntuk(Collection $baris): array
    {
        return $this->ringkasan($baris);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return array<string, int>
     */
    protected function ringkasan(Collection $baris): array
    {
        return [
            'pegawai' => $baris->count(),
            'hadir' => $baris->sum('hadir'),
            'terlambat' => $baris->sum('terlambat'),
            'tanpa_keterangan' => $baris->sum('tanpa_keterangan'),
        ];
    }

    /**
     * @return Collection<int, EventAbsen>
     */
    protected function eventPadaRentang(Carbon $dari, Carbon $sampai): Collection
    {
        /*
         * `whereDate()`, bukan `whereBetween('tanggal', [...])` mentah:
         * `tanggal` bercast `date`, dan sebagian penyimpanan (SQLite pada
         * lingkungan uji, tanpa tipe DATE sungguhan) menuliskannya sebagai
         * "2026-09-11 00:00:00" apa adanya. Dibandingkan sebagai STRING
         * terhadap batas atas "2026-09-11", nilai itu justru lebih besar
         * secara leksikografis — batas atasnya sendiri diam-diam
         * TEREKSKLUSI. MySQL produksi tidak pernah menampakkan ini karena
         * kolom DATE sungguhan memotong jamnya saat disimpan, tetapi query
         * yang benar tidak boleh bergantung pada kebetulan mesin
         * penyimpanannya. `whereDate()` membandingkan sebagai tanggal di
         * kedua sisi, pada mesin basis data mana pun.
         */
        return EventAbsen::query()
            ->with('unitKerja:id')
            ->whereDate('tanggal', '>=', $dari->toDateString())
            ->whereDate('tanggal', '<=', $sampai->toDateString())
            ->get();
    }

    /**
     * Berapa event yang berlaku untuk setiap unit kerja.
     *
     * Cakupan event dinyatakan pada unit level teratas, sedangkan pegawai
     * menaut ke seksi/subbag — jadi cakupannya diperluas ke seluruh turunan
     * lebih dulu. Event "semua unit" berlaku untuk unit mana pun.
     *
     * @param  Collection<int, EventAbsen>  $event
     * @return array<int, int>
     */
    protected function eventPerUnit(Collection $event): array
    {
        $semuaUnitId = UnitKerja::query()->pluck('id')->all();
        $jumlah = [];

        foreach ($event as $satu) {
            $tercakup = $satu->berlakuUntukSemuaUnit()
                ? $semuaUnitId
                : UnitKerja::idsDenganTurunan($satu->unitKerja->pluck('id')->all());

            foreach ($tercakup as $unitId) {
                $jumlah[$unitId] = ($jumlah[$unitId] ?? 0) + 1;
            }
        }

        return $jumlah;
    }

    /**
     * Kehadiran per pegawai: berapa event dihadiri dan berapa di antaranya
     * terlambat.
     *
     * @param  Collection<int, int>  $eventIds
     * @param  Collection<int, int>  $pegawaiIds
     * @return array<int, array{hadir: int, terlambat: int}>
     */
    protected function kehadiran(Collection $eventIds, Collection $pegawaiIds): array
    {
        if ($eventIds->isEmpty()) {
            return [];
        }

        return Absensi::query()
            ->where('jenis', JenisAbsen::Datang)
            ->whereIn('event_absen_id', $eventIds)
            ->whereIn('pegawai_id', $pegawaiIds)
            ->selectRaw('pegawai_id, count(*) as hadir')
            ->selectRaw('sum(case when status_ketepatan = ? then 1 else 0 end) as terlambat', [
                StatusKetepatan::Terlambat->value,
            ])
            ->groupBy('pegawai_id')
            ->get()
            ->mapWithKeys(fn ($baris) => [
                (int) $baris->pegawai_id => [
                    'hadir' => (int) $baris->hadir,
                    'terlambat' => (int) $baris->terlambat,
                ],
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $cakupan
     * @return Collection<int, Pegawai>
     */
    protected function pegawaiTerpilih(array $cakupan): Collection
    {
        return Pegawai::query()
            ->with('unitKerja:id,kode,nama')
            ->where('aktif', true)
            ->whereIn('unit_kerja_id', $cakupan)
            ->get();
    }

    /**
     * @return array<int, int>|null
     */
    protected function cakupanPengguna(User $pelaku): ?array
    {
        return $pelaku->lintasUnit()
            ? null
            : UnitKerja::idsDenganTurunan($pelaku->unit_kerja_id);
    }

    /**
     * Cakupan yang benar-benar ditampilkan: irisan antara hak pengguna dan
     * unit yang dipilih pada penyaring (FR-LAP-01).
     *
     * @param  array<int, int>|null  $cakupanPengguna
     * @return array<int, int>
     */
    protected function cakupanTampilan(?array $cakupanPengguna, ?int $unitKerjaId): array
    {
        $dipilih = $unitKerjaId === null
            ? UnitKerja::query()->pluck('id')->all()
            : UnitKerja::idsDenganTurunan($unitKerjaId);

        return $cakupanPengguna === null
            ? $dipilih
            : array_values(array_intersect($dipilih, $cakupanPengguna));
    }
}
