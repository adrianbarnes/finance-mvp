<?php

namespace Database\Factories;

use App\Enums\Language;
use App\Models\VoicePrompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VoicePrompt>
 */
class VoicePromptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'language' => Language::Twi,
            'text' => fake()->sentence(),
        ];
    }
}
