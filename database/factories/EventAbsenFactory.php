<?php

namespace Database\Factories;

use App\Enums\CakupanEvent;
use App\Enums\JenisEvent;
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

    /**
     * Sesi absen harian yang dibuka sistem, bukan kegiatan buatan admin.
     *
     * Bawaan factory adalah kegiatan, karena itulah yang dibuat manusia dan
     * karena itu pula yang paling sering diuji. Sesi harian punya perlakuan
     * yang berbeda di beberapa tempat — antara lain ia TIDAK dihitung sebagai
     * kegiatan yang lupa ditutup — sehingga uji yang membedakannya perlu cara
     * untuk membuatnya.
     */
    public function umum(): static
    {
        return $this->state(fn (array $attributes) => [
            'jenis' => JenisEvent::Umum,
            'nama' => 'Absen Umum',
        ]);
    }

    public function ditutup(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StatusEvent::Ditutup,
            'ditutup_pada' => now(),
        ]);
    }
}
