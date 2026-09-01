<?php

namespace Database\Factories;

use App\Enums\CakupanEvent;
use App\Enums\StatusEvent;
use App\Models\EventAbsen;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventAbsen>
 */
class EventAbsenFactory extends Factory
{
    protected $model = EventAbsen::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nama' => 'Apel '.fake()->words(2, true),
            'tanggal' => fake()->dateTimeBetween('-30 days', '+30 days')->format('Y-m-d'),
            'jam_mulai' => '07:30',
            'toleransi_menit' => 15,
            'cakupan' => CakupanEvent::Unit,
            'status' => StatusEvent::Aktif,
            'dibuat_oleh' => User::factory(),
            'catatan' => null,
        ];
    }

    public function semuaUnit(): static
    {
        return $this->state(fn (array $attributes) => ['cakupan' => CakupanEvent::SemuaUnit]);
    }

    public function ditutup(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StatusEvent::Ditutup,
            'ditutup_pada' => now(),
        ]);
    }
}
