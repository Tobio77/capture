<?php

namespace Database\Factories;

use App\Models\UnitKerja;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UnitKerja>
 */
class UnitKerjaFactory extends Factory
{
    protected $model = UnitKerja::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kode' => Str::upper(fake()->unique()->bothify('UPT-???-##')),
            'nama' => 'UPT '.fake()->city(),
            'aktif' => true,
        ];
    }

    public function nonaktif(): static
    {
        return $this->state(fn (array $attributes) => ['aktif' => false]);
    }
}
