<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UbahStatusUnitKerjaRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'aktif' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'aktif' => 'status unit kerja',
        ];
    }
}
