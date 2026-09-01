<?php

namespace App\Http\Requests;

use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Services\KartuRfidService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pendaftaran kartu RFID ke seorang pegawai (FR-TAP-03).
 */
class DaftarkanKartuRequest extends FormRequest
{
    public function authorize(): bool
    {
        $pengguna = $this->user();

        if ($pengguna->lintasUnit()) {
            return true;
        }

        $pegawai = $this->route('pegawai');

        return $pegawai instanceof Pegawai
            && in_array(
                $pegawai->unit_kerja_id,
                UnitKerja::idsDenganTurunan($pengguna->unit_kerja_id),
                true,
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $pegawai = $this->route('pegawai');

        return [
            'uid_kartu' => [
                'required',
                'string',
                'min:4',
                'max:32',

                // Satu kartu tidak boleh mewakili dua orang: tap-nya akan
                // menjadi ambigu dan absensinya salah alamat.
                Rule::unique('pegawai', 'uid_kartu')->ignore($pegawai?->id),
            ],
        ];
    }

    /**
     * Reader menuliskan UID dengan gaya berbeda-beda antar merek; nilainya
     * dinormalkan lebih dulu agar pemeriksaan keunikan menilai kartu yang
     * sama sebagai satu kartu.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'uid_kartu' => KartuRfidService::normalkan((string) $this->input('uid_kartu')),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['uid_kartu' => 'UID kartu'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'uid_kartu.required' => 'Tap kartu pada reader untuk mengisi UID.',
            'uid_kartu.unique' => 'Kartu ini sudah terdaftar atas nama pegawai lain.',
            'uid_kartu.min' => 'UID kartu terlalu pendek untuk dianggap sah.',
        ];
    }
}
