<?php

namespace App\Http\Requests;

use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Services\FotoReferensiWajahService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Pendaftaran/pembaruan foto referensi wajah (FR-PEG-05).
 *
 * Embedding datang sudah terhitung dari browser; yang diperiksa di sini adalah
 * bentuknya — server tidak pernah memproses wajah sendiri.
 */
class DaftarkanWajahRequest extends FormRequest
{
    public function authorize(): bool
    {
        $pengguna = $this->user();

        if ($pengguna->lintasUnit()) {
            return true;
        }

        // Admin UPT hanya boleh mendaftarkan pegawai unitnya sendiri,
        // termasuk seksi/subbag di bawahnya (SRS §6).
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
        return [
            'foto' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:5120'],
            'embedding' => ['required', 'array'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! FotoReferensiWajahService::embeddingSah($this->input('embedding'))) {
                $validator->errors()->add(
                    'embedding',
                    'Data wajah tidak sah. Ulangi pendaftaran dari halaman ini.',
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
            'foto' => 'foto referensi',
            'embedding' => 'data wajah',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'foto.required' => 'Foto referensi wajib dipilih.',
            'foto.image' => 'Berkas harus berupa gambar.',
            'foto.mimes' => 'Foto harus berformat JPG atau PNG.',
            'foto.max' => 'Ukuran foto maksimal 5 MB.',
        ];
    }
}
