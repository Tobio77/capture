<?php

namespace App\Http\Requests;

use App\Enums\PeranPengguna;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Pembuatan/pembaruan akun admin (FR-USR-01).
 *
 * Hanya Superadmin yang boleh menyentuh akun admin; pembatasan perannya
 * dipasang pada route (matriks peran SRS §6).
 */
class SimpanPenggunaRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $pengguna = $this->route('pengguna');

        return [
            'nama' => ['required', 'string', 'max:150'],
            'email' => [
                'required', 'email', 'max:150',
                Rule::unique('users', 'email')->ignore($pengguna?->id),
            ],
            'role' => ['required', Rule::enum(PeranPengguna::class)],
            'unit_kerja_id' => ['nullable', 'integer', 'exists:unit_kerja,id'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->periksaCakupan($validator);
            $this->periksaPerubahanDiriSendiri($validator);
        });
    }

    /**
     * Admin UPT tanpa unit kerja tidak punya cakupan sama sekali dan akan
     * melihat halaman kosong di mana-mana; sebaliknya, unit pada peran lintas
     * unit adalah cakupan bayangan yang tidak pernah dipakai.
     */
    protected function periksaCakupan(Validator $validator): void
    {
        $adminUpt = $this->input('role') === PeranPengguna::AdminUpt->value;
        $unitId = $this->input('unit_kerja_id');

        if (! $adminUpt) {
            return;
        }

        if (blank($unitId)) {
            $validator->errors()->add('unit_kerja_id', 'Admin UPT wajib memiliki unit kerja.');

            return;
        }

        // Cakupan admin dinyatakan pada unit level teratas, sama seperti
        // cakupan event (SDD §3.1).
        $bolehDipilih = UnitKerja::query()
            ->levelTeratas()
            ->whereKey($unitId)
            ->exists();

        if (! $bolehDipilih) {
            $validator->errors()->add(
                'unit_kerja_id',
                'Cakupan admin hanya dapat memakai unit kerja level teratas.',
            );
        }
    }

    /**
     * Superadmin tidak boleh menurunkan perannya sendiri.
     *
     * Tanpa penjagaan ini, satu-satunya Superadmin dapat mengunci dirinya
     * keluar dari menu Kelola User/Role dan tidak ada jalan kembali dari
     * dalam aplikasi.
     */
    protected function periksaPerubahanDiriSendiri(Validator $validator): void
    {
        $sasaran = $this->route('pengguna');

        if (! $sasaran instanceof User || $sasaran->id !== $this->user()->id) {
            return;
        }

        if ($this->input('role') !== $sasaran->role->value) {
            $validator->errors()->add('role', 'Anda tidak dapat mengubah peran akun Anda sendiri.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nama' => 'nama',
            'email' => 'alamat surel',
            'role' => 'peran',
            'unit_kerja_id' => 'unit kerja',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Alamat surel ini sudah dipakai akun lain.',
        ];
    }
}
