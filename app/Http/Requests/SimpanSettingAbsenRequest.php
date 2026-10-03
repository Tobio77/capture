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
            'absen_event_aktif' => ['required', 'boolean'],
            'jam_masuk_umum' => ['required', 'date_format:H:i'],

            // FR-SET-07. Jam tutup TIDAK diharuskan lebih besar daripada jam
            // buka: jendela yang melewati tengah malam sah adanya bagi sif
            // malam, dan AbsenUmumService::didalamJendela() menanganinya.
            'jam_buka_datang' => ['required', 'date_format:H:i'],
            'jam_tutup_datang' => ['required', 'date_format:H:i'],
            'jam_buka_pulang' => ['required', 'date_format:H:i'],
            'jam_tutup_pulang' => ['required', 'date_format:H:i'],

            /*
             * Jadwal jam per hari (Senin–Minggu) — tujuh baris persis, tidak
             * kurang tidak lebih; `hari` harus salah satu dari 1..7 (ISO) dan
             * tidak boleh berulang, supaya SettingAbsenService::rapikanJadwal()
             * tidak diam-diam kehilangan atau menduplikasi satu hari.
             */
            'jadwal_mingguan' => ['required', 'array', 'size:7'],
            'jadwal_mingguan.*.hari' => [
                'required', 'integer', 'distinct', Rule::in(SettingAbsenService::HARI_ISO),
            ],
            'jadwal_mingguan.*.jam_masuk' => ['required', 'date_format:H:i'],
            'jadwal_mingguan.*.jam_buka_datang' => ['required', 'date_format:H:i'],
            'jadwal_mingguan.*.jam_tutup_datang' => ['required', 'date_format:H:i'],
            'jadwal_mingguan.*.jam_buka_pulang' => ['required', 'date_format:H:i'],
            'jadwal_mingguan.*.jam_tutup_pulang' => ['required', 'date_format:H:i'],

            'pendaftaran_perangkat_aktif' => ['required', 'boolean'],

            'ambang_kehadiran_minimum' => [
                'required', 'integer',
                'min:'.SettingAbsenService::AMBANG_KEHADIRAN_MIN,
                'max:'.SettingAbsenService::AMBANG_KEHADIRAN_MAKS,
            ],
            'ambang_keterlambatan_maksimum' => [
                'required', 'integer',
                'min:'.SettingAbsenService::AMBANG_KETERLAMBATAN_MIN,
                'max:'.SettingAbsenService::AMBANG_KETERLAMBATAN_MAKS,
            ],
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
            'absen_umum_aktif' => 'fitur absen umum',
            'absen_event_aktif' => 'fitur absen event',
            'jam_masuk_umum' => 'jam masuk harian',
            'jam_buka_datang' => 'jam buka absen datang',
            'jam_tutup_datang' => 'jam tutup absen datang',
            'jam_buka_pulang' => 'jam buka absen pulang',
            'jam_tutup_pulang' => 'jam tutup absen pulang',
            'jadwal_mingguan' => 'jadwal jam mingguan',
            'pendaftaran_perangkat_aktif' => 'mode pendaftaran perangkat',
            'ambang_kehadiran_minimum' => 'ambang kehadiran minimum',
            'ambang_keterlambatan_maksimum' => 'ambang keterlambatan maksimum',
        ];
    }
}
