<?php

namespace App\Http\Requests;

use App\Services\EventAbsenService;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Pembuatan/pembaruan event absensi (FR-EVT-01, FR-EVT-02).
 *
 * Tidak ada lagi medan cakupan maupun daftar unit kerja: sejak S49 setiap
 * event berlaku bagi SELURUH dinas. Hak memilih cakupan karena itu tidak perlu
 * diperiksa lagi — yang diperiksa hanyalah hak membuat event sama sekali, dan
 * itu ditegakkan middleware `peran:superadmin,admin_dinas` pada route.
 */
class SimpanEventRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:150'],
            'tanggal' => ['required', 'date'],
            'jam_mulai' => ['required', 'date_format:H:i'],
            'toleransi_menit' => [
                'required', 'integer', 'min:0', 'max:'.SettingAbsenService::TOLERANSI_MAKS_MENIT,
            ],
            'catatan' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->periksaBentrok($validator);
        });
    }

    /**
     * FR-EVT-06: hanya boleh ada satu event kegiatan yang aktif.
     *
     * Sebelum S49 syaratnya adalah "cakupan unitnya tidak beririsan"; karena
     * setiap event kini mencakup seluruh dinas, dua event aktif selalu
     * beririsan dan syarat itu menyusut menjadi satu kalimat. Jadwalnya tidak
     * ikut menentukan — yang menentukan adalah status — sehingga menutup event
     * yang lebih dulu berjalan adalah satu-satunya jalan keluarnya.
     */
    protected function periksaBentrok(Validator $validator): void
    {
        $bentrok = app(EventAbsenService::class)->eventBentrok(kecuali: $this->route('event'));

        if ($bentrok === null) {
            return;
        }

        $validator->errors()->add('nama', sprintf(
            'Event "%s" (%s) masih dibuka dan berlaku bagi seluruh unit kerja. Tutup event tersebut lebih dulu.',
            $bentrok->nama,
            $bentrok->tanggal->format('d-m-Y'),
        ));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nama' => 'nama event',
            'tanggal' => 'tanggal',
            'jam_mulai' => 'jam mulai',
            'toleransi_menit' => 'toleransi keterlambatan',
            'catatan' => 'catatan',
        ];
    }
}
