<?php

namespace App\Http\Requests\Kiosk;

use Illuminate\Foundation\Http\FormRequest;

class AktivasiKioskRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'kode_aktivasi' => ['required', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kode_aktivasi' => 'kode aktivasi',
        ];
    }
}
