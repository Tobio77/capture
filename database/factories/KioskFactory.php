<?php

namespace Database\Factories;

use App\Enums\StatusKiosk;
use App\Models\Kiosk;
use App\Models\UnitKerja;
use App\Services\KioskService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Kiosk>
 */
class KioskFactory extends Factory
{
    protected $model = Kiosk::class;

    /**
     * Kiosk baru: sudah didaftarkan admin, tetapi perangkatnya belum diaktifkan.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nama_titik' => fake()->randomElement(['Aula', 'Lobi', 'Ruang Rapat', 'Workshop']).' '.fake()->city(),
            'unit_kerja_id' => UnitKerja::factory(),
            'device_token' => null,
            'kode_aktivasi' => null,
            'kode_aktivasi_kedaluwarsa_at' => null,
            'ip_terakhir' => null,
            'status' => StatusKiosk::Offline,
            'login_terakhir_at' => null,
            'diaktifkan_pada' => null,
            'aktif' => true,
        ];
    }

    /**
     * Kiosk yang sudah dibekali kode aktivasi dan menunggu perangkat menukarkannya.
     */
    public function menungguAktivasi(string $kode = 'ABCD2345'): static
    {
        return $this->state(fn (array $attributes) => [
            'kode_aktivasi' => $kode,
            'kode_aktivasi_kedaluwarsa_at' => now()->addHours(KioskService::MASA_KODE_JAM),
        ]);
    }

    /**
     * Kode aktivasi yang sudah lewat masa berlakunya.
     */
    public function kodeKedaluwarsa(string $kode = 'ABCD2345'): static
    {
        return $this->state(fn (array $attributes) => [
            'kode_aktivasi' => $kode,
            'kode_aktivasi_kedaluwarsa_at' => now()->subHour(),
        ]);
    }

    /**
     * Perangkat yang sudah memegang device_token.
     */
    public function diaktifkan(string $token = 'token-perangkat-uji'): static
    {
        return $this->state(fn (array $attributes) => [
            'device_token' => KioskService::hashToken($token),
            'kode_aktivasi' => null,
            'kode_aktivasi_kedaluwarsa_at' => null,
            'ip_terakhir' => '127.0.0.1',
            'status' => StatusKiosk::Online,
            'login_terakhir_at' => now(),
            'diaktifkan_pada' => now(),
        ]);
    }

    public function nonaktif(): static
    {
        return $this->state(fn (array $attributes) => ['aktif' => false]);
    }
}
