<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class MasukRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:150'],
            'password' => ['required', 'string'],
            'ingat_saya' => ['boolean'],

            /*
             * Tidak pernah `required` di sini. Wajib atau tidaknya ditentukan
             * jumlah kegagalan berturut-turut, dan itu diketahui
             * AutentikasiService — bukan aturan validasi yang berlaku sama bagi
             * semua orang, termasuk admin yang baru pertama kali mencoba.
             */
            'jawaban_captcha' => ['nullable', 'string', 'max:8'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'alamat surel',
            'password' => 'kata sandi',
            'jawaban_captcha' => 'jawaban hitungan',
        ];
    }
}
