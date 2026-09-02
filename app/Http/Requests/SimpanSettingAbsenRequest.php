<?php

namespace App\Http\Requests;

use App\Enums\KompresiFoto;
use App\Services\SettingAbsenService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Setting Absen (FR-SET-01 s.d. FR-SET-04).
 *
 * Otorisasi peran ditangani middleware `peran:superadmin,admin_dinas` pada
 * route — Setting Absen adalah pengaturan global sistem, bukan milik satu unit.
 */
class SimpanSettingAbsenRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'metode_manual_aktif' => ['required', 'boolean'],
            'metode_rfid_aktif' => ['required', 'boolean'],
            'metode_wajah_aktif' => ['required', 'boolean'],
            'toleransi_default_menit' => [
                'required', 'integer', 'min:0', 'max:'.SettingAbsenService::TOLERANSI_MAKS_MENIT,
            ],
            'ambang_kecocokan_wajah' => [
                'required', 'integer',
                'min:'.SettingAbsenService::AMBANG_MIN,
                'max:'.SettingAbsenService::AMBANG_MAKS,
            ],
            'kompresi_foto' => ['required', Rule::enum(KompresiFoto::class)],
            'absen_umum_aktif' => ['required', 'boolean'],
            'jam_masuk_umum' => ['required', 'date_format:H:i'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Mematikan seluruh metode membuat absensi mustahil dilakukan;
            // pengaturan yang mengunci sistemnya sendiri ditolak di sini.
            $adaYangAktif = $this->boolean('metode_manual_aktif')
                || $this->boolean('metode_rfid_aktif')
                || $this->boolean('metode_wajah_aktif');

            if (! $adaYangAktif) {
                $validator->errors()->add(
                    'metode_manual_aktif',
                    'Minimal satu metode absen harus aktif.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'metode_manual_aktif' => 'metode input manual',
            'metode_rfid_aktif' => 'metode tap RFID',
            'metode_wajah_aktif' => 'metode verifikasi wajah',
            'toleransi_default_menit' => 'toleransi keterlambatan default',
            'ambang_kecocokan_wajah' => 'ambang kecocokan wajah',
            'kompresi_foto' => 'kompresi foto absen',
            'absen_umum_aktif' => 'absen umum harian',
            'jam_masuk_umum' => 'jam masuk harian',
        ];
    }
}
