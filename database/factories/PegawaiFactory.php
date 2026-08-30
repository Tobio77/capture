<?php

namespace Database\Factories;

use App\Models\Pegawai;
use App\Models\UnitKerja;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pegawai>
 */
class PegawaiFactory extends Factory
{
    protected $model = Pegawai::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nip' => fake()->unique()->numerify('##################'),
            'nama' => fake()->name(),
            'unit_kerja_id' => UnitKerja::factory(),
            'jabatan' => fake()->randomElement([
                'Analis Ketenagakerjaan',
                'Instruktur Pelatihan',
                'Pengadministrasi Umum',
                'Pengantar Kerja',
                'Pranata Komputer',
            ]),
            'foto_referensi_path' => null,
            'wajah_terdaftar' => false,
            'sumber_sinkron_terakhir' => now(),
        ];
    }

    /**
     * Pegawai yang foto referensi wajahnya sudah terdaftar (FR-PEG-05).
     */
    public function wajahTerdaftar(): static
    {
        return $this->state(fn (array $attributes) => [
            'foto_referensi_path' => 'referensi-wajah/'.fake()->uuid().'.jpg',
            'wajah_terdaftar' => true,
        ]);
    }
}
