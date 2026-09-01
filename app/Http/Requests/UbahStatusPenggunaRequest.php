<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Aktifkan/nonaktifkan akun admin (FR-USR-01).
 */
class UbahStatusPenggunaRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['aktif' => ['required', 'boolean']];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $sasaran = $this->route('pengguna');

            /*
             * Menonaktifkan akun sendiri akan langsung memutus akses lewat
             * middleware `pengguna.aktif`, dan bila ia satu-satunya Superadmin
             * tidak ada jalan kembali dari dalam aplikasi.
             */
            if ($sasaran instanceof User
                && $sasaran->id === $this->user()->id
                && ! $this->boolean('aktif')
            ) {
                $validator->errors()->add('aktif', 'Anda tidak dapat menonaktifkan akun Anda sendiri.');
            }
        });
    }
}
