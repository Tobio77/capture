<?php

namespace Database\Factories;

use App\Models\EventAbsen;
use App\Models\KodeUnitEvent;
use App\Models\UnitKerja;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KodeUnitEvent>
 */
class KodeUnitEventFactory extends Factory
{
    protected $model = KodeUnitEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_absen_id' => EventAbsen::factory(),
            'unit_kerja_id' => UnitKerja::factory(),

            /*
             * Sekadar nilai unik. Abjad tanpa 0/O dan 1/I adalah keputusan
             * KodeUnitEventService::kodeAcak() — yang berlaku pada kode
             * sungguhan yang dibacakan ke petugas, bukan pada data uji.
             */
            'kode' => Str::upper(Str::random(8)),
        ];
    }
}
