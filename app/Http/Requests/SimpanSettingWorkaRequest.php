<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SimpanSettingWorkaRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'api_url' => ['required', 'string', 'max:255', 'url'],
            // Dikosongkan berarti token lama dipertahankan.
            'api_token' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'api_url' => 'alamat API WORKA',
            'api_token' => 'token API WORKA',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'api_url.url' => 'Alamat API WORKA harus berupa URL lengkap, contoh: http://worka.test',
        ];
    }
}
