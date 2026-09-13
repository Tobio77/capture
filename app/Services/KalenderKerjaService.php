<?php

namespace App\Services;

use App\Models\HariLibur;
use App\Models\UnitKerja;
use Illuminate\Support\Carbon;

/**
 * Kalender hari kerja per unit kerja (FR-SET-08).
 *
 * Menjawab satu pertanyaan: apakah tanggal ini hari kerja bagi unit ini, dan
 * kalau bukan, mengapa. Fungsi INILAH — bukan penghitungan ulang di masing-
 * masing fitur — yang dipakai jendela buka/tutup Absen Umum
 * ({@see AbsenUmumService::status()}), penanda `Absensi.hari_libur`, dan
 * penyebut "hari kerja" pada Laporan Resmi.
 *
 * Dua sumber "bukan hari kerja", dan keduanya diperiksa, hari libur lebih
 * dahulu:
 *
 *   1. Tanggal yang terdaftar sebagai hari libur — nasional maupun khusus unit.
 *   2. Hari dalam pekan yang memang bukan hari kerja unit itu.
 *
 * **Apa yang pemanggil lakukan dengan jawaban "bukan hari kerja" bukan urusan
 * kelas ini**, dan itu memang sengaja. Sejak revisi kalender kerja, jendela
 * Absen Umum menutup dirinya sendiri di luar hari kerja (kecuali override
 * manual) — sebelum revisi itu (S39) ia hanya menandai tanpa menutup.
 * Penanda `Absensi.hari_libur` sendiri tidak pernah berubah maknanya: tap
 * yang diterima lewat override pada hari libur tetap tercatat dengan
 * penanda itu, sebagai catatan administratif yang tidak boleh berubah arti
 * ketika kalender kelak disunting — lihat {@see self::alasanLibur()}.
 */
class KalenderKerjaService
{
    /** Hari kerja bawaan bila unit dan seluruh induknya belum mengaturnya. */
    public const array HARI_KERJA_BAWAAN = [1, 2, 3, 4, 5];

    /**
     * @var array<string, array<int, int>>
     */
    protected array $ingatanHariKerja = [];

    /**
     * @var array<string, ?string>
     */
    protected array $ingatanLibur = [];

    /**
     * Apakah tanggal ini hari kerja bagi unit ini.
     */
    public function hariKerja(?int $unitKerjaId, ?Carbon $tanggal = null): bool
    {
        return $this->alasanLibur($unitKerjaId, $tanggal) === null;
    }

    /**
     * Alasan tanggal ini BUKAN hari kerja, atau null bila ia hari kerja.
     *
     * Mengembalikan kalimat siap tampil, bukan penanda: yang membacanya di
     * layar tap adalah petugas, dan "libur" saja tidak memberi tahu apakah ini
     * akhir pekan biasa atau tanggal merah yang ia lupa.
     */
    public function alasanLibur(?int $unitKerjaId, ?Carbon $tanggal = null): ?string
    {
        $hari = ($tanggal ?? Carbon::today())->startOfDay();
        $kunci = $unitKerjaId.'|'.$hari->toDateString();

        if (array_key_exists($kunci, $this->ingatanLibur)) {
            return $this->ingatanLibur[$kunci];
        }

        return $this->ingatanLibur[$kunci] = $this->hitungAlasan($unitKerjaId, $hari);
    }

    protected function hitungAlasan(?int $unitKerjaId, Carbon $hari): ?string
    {
        /*
         * Tanggal libur diperiksa LEBIH DAHULU daripada hari dalam pekan.
         * Sebuah tanggal merah yang jatuh pada hari Rabu harus menyebut
         * namanya — "Hari Raya Idulfitri" — bukan didiamkan karena Rabu
         * kebetulan hari kerja.
         */
        $libur = HariLibur::query()
            ->whereDate('tanggal', $hari)
            ->when(
                $unitKerjaId !== null,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('unit_kerja_id')
                    ->orWhereIn('unit_kerja_id', UnitKerja::idsLeluhurDan($unitKerjaId))),
                fn ($q) => $q->whereNull('unit_kerja_id'),
            )
            ->orderByRaw('unit_kerja_id IS NULL')
            ->first();

        if ($libur !== null) {
            return $libur->keterangan;
        }

        if (in_array($hari->dayOfWeekIso, $this->hariKerjaUnit($unitKerjaId), true)) {
            return null;
        }

        return $hari->translatedFormat('l').' — bukan hari kerja unit ini';
    }

    /**
     * Hari kerja unit ini, menelusuri induk bila ia belum mengaturnya sendiri.
     *
     * @return array<int, int>
     */
    public function hariKerjaUnit(?int $unitKerjaId): array
    {
        if ($unitKerjaId === null) {
            return self::HARI_KERJA_BAWAAN;
        }

        $kunci = (string) $unitKerjaId;

        if (isset($this->ingatanHariKerja[$kunci])) {
            return $this->ingatanHariKerja[$kunci];
        }

        /*
         * Ditelusuri dari unit itu sendiri ke atas. Sebuah seksi jarang punya
         * jadwal sendiri; yang punya biasanya UPT-nya, dan menuntut setiap
         * seksi mengaturnya ulang hanya melahirkan puluhan salinan yang akan
         * berbeda diam-diam.
         */
        foreach (UnitKerja::idsLeluhurDan($unitKerjaId) as $id) {
            $hari = UnitKerja::query()->whereKey($id)->value('hari_kerja');

            if (is_array($hari) && $hari !== []) {
                return $this->ingatanHariKerja[$kunci] = array_map('intval', $hari);
            }
        }

        return $this->ingatanHariKerja[$kunci] = self::HARI_KERJA_BAWAAN;
    }
}
