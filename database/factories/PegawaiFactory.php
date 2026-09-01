<?php

namespace Database\Factories;

use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Services\FotoReferensiWajahService;
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
    /**
     * Wajah yang sudah terdaftar selalu membawa embedding-nya.
     *
     * Invarian ini dijaga sejak data contoh: `wajah_terdaftar` tanpa
     * `embedding_wajah` adalah keadaan setengah jadi yang tidak dapat dipakai
     * kiosk untuk mencocokkan wajah (FR-PEG-05, SDD §3.2).
     */
    public function wajahTerdaftar(): static
    {
        return $this->state(fn (array $attributes) => [
            'foto_referensi_path' => 'foto-referensi/'.fake()->uuid().'.jpg',
            'embedding_wajah' => array_map(
                fn () => fake()->randomFloat(6, -1, 1),
                range(1, FotoReferensiWajahService::DIMENSI_EMBEDDING),
            ),
            'wajah_terdaftar' => true,
            'wajah_didaftarkan_at' => now(),
        ]);
    }
}
