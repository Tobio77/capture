<?php

namespace Database\Factories;

use App\Models\UnitKerja;
use App\Services\KodeUnitService;
use App\Services\UnitKerjaService;
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

    /**
     * Kode perangkat terbit bersamaan dengan unitnya, sama seperti di
     * produksi ({@see UnitKerjaService::buat()} dan
     * sinkronisasi WORKA).
     *
     * Lewat afterCreating, bukan lewat definition(): `kode_perangkat` sengaja
     * tidak fillable ({@see UnitKerja}), sehingga menyebutnya di antara
     * atribut hanya akan membuatnya diam-diam dibuang.
     */
    public function configure(): static
    {
        return $this->afterCreating(
            fn (UnitKerja $unit) => app(KodeUnitService::class)->pastikanAda($unit),
        );
    }

    public function nonaktif(): static
    {
        return $this->state(fn (array $attributes) => ['aktif' => false]);
    }

    /** Unit yang belum pernah memperoleh kode perangkat. */
    public function tanpaKodePerangkat(): static
    {
        return $this->afterCreating(
            fn (UnitKerja $unit) => $unit->forceFill(['kode_perangkat' => null])->save(),
        );
    }
}
