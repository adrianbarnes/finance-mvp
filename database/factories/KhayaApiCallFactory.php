<?php

namespace Database\Factories;

use App\Models\KhayaApiCall;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KhayaApiCall>
 */
class KhayaApiCallFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'endpoint' => 'asr.transcribe',
            'language' => 'twi',
            'http_status' => 200,
            'latency_ms' => fake()->numberBetween(300, 3000),
        ];
    }
}
