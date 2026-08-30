<?php

namespace Database\Factories;

use App\Enums\PeranPengguna;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nama' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => PeranPengguna::AdminDinas,
            'unit_kerja_id' => null,
            'aktif' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function superadmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => PeranPengguna::Superadmin,
            'unit_kerja_id' => null,
        ]);
    }

    /**
     * Admin UPT selalu terikat pada satu unit kerja.
     */
    public function adminUpt(?UnitKerja $unitKerja = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => PeranPengguna::AdminUpt,
            'unit_kerja_id' => $unitKerja?->id ?? UnitKerja::factory(),
        ]);
    }

    public function nonaktif(): static
    {
        return $this->state(fn (array $attributes) => ['aktif' => false]);
    }
}
