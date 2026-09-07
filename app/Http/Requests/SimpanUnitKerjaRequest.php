<?php

namespace App\Http\Requests;

use App\Services\UnitKerjaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimpanUnitKerjaRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $unitKerja = $this->route('unit_kerja');

        return [
            'kode' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9\-]+$/',
                Rule::unique('unit_kerja', 'kode')->ignore($unitKerja?->id),
            ],
            'nama' => ['required', 'string', 'max:150'],

            /*
             * Hari kerja unit ini (FR-SET-08), sebagai nomor hari ISO-8601.
             * Larik kosong TIDAK sama dengan "tidak bekerja sama sekali": ia
             * berarti unit ini belum mengaturnya dan mewarisi induknya, dan
             * karena itu disimpan sebagai null.
             */
            'hari_kerja' => ['nullable', 'array'],
            'hari_kerja.*' => ['integer', 'between:1,7'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('kode')) {
            $this->merge(['kode' => UnitKerjaService::normalkanKode($this->string('kode')->toString())]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kode' => 'kode unit kerja',
            'nama' => 'nama unit kerja',
            'hari_kerja' => 'hari kerja',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kode.regex' => 'Kode unit kerja hanya boleh berisi huruf, angka, dan tanda hubung.',
            'kode.unique' => 'Kode unit kerja tersebut sudah digunakan unit lain.',
        ];
    }
}
