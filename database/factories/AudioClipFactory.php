<?php

namespace Database\Factories;

use App\Models\AudioClip;
use App\Models\CallFlow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AudioClip>
 */
class AudioClipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'call_flow_id' => CallFlow::factory(),
            'key' => fake()->unique()->slug(2),
            'group' => 'other',
        ];
    }
}
