<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Penyaring laporan kehadiran (FR-LAP-01).
 *
 * Tanpa rentang yang disebutkan, laporan menampilkan bulan berjalan — periode
 * yang paling sering diminta untuk rekap kepegawaian.
 */
class FilterLaporanRequest extends FormRequest
{
    /** Batas panjang rentang, menjaga laporan tetap wajar dihitung. */
    public const int MAKS_HARI = 366;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dari' => ['nullable', 'date'],

            /*
             * Urutan tanggal sengaja tidak divalidasi: rentang terbalik
             * dibetulkan sendiri oleh rentang(), dan kedua kolom dikembalikan
             * ke layar dalam keadaan sudah tertukar sehingga admin melihat
             * rentang yang benar-benar dipakai.
             */
            'sampai' => ['nullable', 'date'],
            'unit_kerja_id' => ['nullable', 'integer', 'exists:unit_kerja,id'],
        ];
    }

    /**
     * Rentang yang sudah dinormalkan beserta unit kerja terpilih.
     *
     * @return array{0: Carbon, 1: Carbon, 2: int|null}
     */
    public function rentang(): array
    {
        $dari = $this->filled('dari')
            ? Carbon::parse($this->input('dari'))->startOfDay()
            : Carbon::now()->startOfMonth();

        $sampai = $this->filled('sampai')
            ? Carbon::parse($this->input('sampai'))->startOfDay()
            : Carbon::now()->endOfMonth()->startOfDay();

        // Rentang terbalik lebih mungkin salah ketik daripada disengaja.
        if ($sampai->lessThan($dari)) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        if ($dari->diffInDays($sampai) > self::MAKS_HARI) {
            $sampai = $dari->copy()->addDays(self::MAKS_HARI);
        }

        return [
            $dari,
            $sampai,
            $this->filled('unit_kerja_id') ? (int) $this->integer('unit_kerja_id') : null,
        ];
    }
}
