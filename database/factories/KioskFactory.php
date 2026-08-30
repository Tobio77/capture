<?php

namespace Database\Factories;

use App\Enums\StatusKiosk;
use App\Models\Kiosk;
use App\Models\UnitKerja;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Kiosk>
 */
class KioskFactory extends Factory
{
    protected $model = Kiosk::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nama_titik' => fake()->randomElement(['Aula', 'Lobi', 'Ruang Rapat', 'Workshop']).' '.fake()->city(),
            'unit_kerja_id' => UnitKerja::factory(),
            'device_token' => Str::random(64),
            'ip_terakhir' => null,
            'status' => StatusKiosk::Offline,
            'login_terakhir_at' => null,
            'aktif' => true,
        ];
    }

    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StatusKiosk::Online,
            'ip_terakhir' => fake()->localIpv4(),
            'login_terakhir_at' => now(),
        ]);
    }
}
