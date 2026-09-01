<?php

namespace App\Http\Requests;

use App\Models\UnitKerja;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Pendaftaran/pembaruan perangkat absen (FR-USR-02).
 *
 * Pembatasan peran (Superadmin & Admin Dinas) dipasang pada route.
 */
class SimpanPerangkatRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $perangkat = $this->route('perangkat');

        return [
            /*
             * Nama titik unik per unit kerja: dua perangkat bernama sama pada
             * satu unit membuat riwayat aktivasi dan detail event mustahil
             * dibaca.
             */
            'nama_titik' => [
                'required', 'string', 'max:150',
                Rule::unique('kiosk', 'nama_titik')
                    ->where('unit_kerja_id', $this->integer('unit_kerja_id'))
                    ->ignore($perangkat?->id),
            ],
            'unit_kerja_id' => ['required', 'integer', 'exists:unit_kerja,id'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Perangkat ditempatkan pada unit level teratas, sama seperti
            // cakupan event dan cakupan admin (SDD §3.1).
            $bolehDipilih = UnitKerja::query()
                ->levelTeratas()
                ->whereKey($this->integer('unit_kerja_id'))
                ->exists();

            if (! $bolehDipilih) {
                $validator->errors()->add(
                    'unit_kerja_id',
                    'Perangkat absen hanya dapat ditempatkan pada unit kerja level teratas.',
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
            'nama_titik' => 'nama titik absen',
            'unit_kerja_id' => 'unit kerja',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama_titik.unique' => 'Sudah ada perangkat dengan nama titik ini pada unit kerja tersebut.',
        ];
    }
}
