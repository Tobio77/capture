<?php

namespace Database\Factories;

use App\Enums\JenisAbsen;
use App\Enums\MetodeAbsen;
use App\Enums\StatusKetepatan;
use App\Models\Absensi;
use App\Models\EventAbsen;
use App\Models\Kiosk;
use App\Models\Pegawai;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Absensi>
 */
class AbsensiFactory extends Factory
{
    protected $model = Absensi::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_absen_id' => EventAbsen::factory(),
            'pegawai_id' => Pegawai::factory(),
            'kiosk_id' => Kiosk::factory(),
            'jenis' => JenisAbsen::Datang,
            'metode' => MetodeAbsen::Manual,
            'waktu' => now(),
            'status_ketepatan' => StatusKetepatan::Tepat,
            'skor_kecocokan_wajah' => 92.5,
            'foto_path' => null,
        ];
    }

    public function pulang(): static
    {
        return $this->state(fn (array $attributes) => [
            'jenis' => JenisAbsen::Pulang,
            'status_ketepatan' => null,
        ]);
    }

    public function terlambat(): static
    {
        return $this->state(fn (array $attributes) => ['status_ketepatan' => StatusKetepatan::Terlambat]);
    }
}
