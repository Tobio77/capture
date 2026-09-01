<?php

namespace App\Http\Requests;

use App\Enums\JenisAbsen;
use App\Enums\MetodeAbsen;
use App\Services\AbsensiService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Pengiriman hasil absen dari kiosk (FR-TAP-05).
 *
 * Otorisasi perangkat ditangani middleware `kiosk`; yang diperiksa di sini
 * hanyalah bentuk kirimannya.
 */
class SimpanAbsenRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id_card' => ['required', 'string', 'max:32'],
            'jenis' => ['required', Rule::enum(JenisAbsen::class)],
            'metode' => ['required', Rule::enum(MetodeAbsen::class)],
            'skor' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'foto' => ['nullable', 'string'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $foto = $this->input('foto');

            if ($foto === null || $foto === '') {
                return;
            }

            $biner = AbsensiService::binerDariDataUri($foto);

            if ($biner === null) {
                $validator->errors()->add('foto', 'Foto absen harus berupa data URI JPEG.');

                return;
            }

            // Foto sudah disusutkan di kiosk sesuai preset; kiriman yang jauh
            // melampaui batas berarti preset diabaikan (NFR-06).
            if (strlen($biner) > AbsensiService::BATAS_FOTO_BYTE) {
                $validator->errors()->add(
                    'foto',
                    'Ukuran foto absen melampaui batas penyimpanan yang diizinkan.',
                );
            }
        });
    }
}
