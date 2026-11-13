<?php

namespace Database\Factories;

use App\Enums\SpeechRecognition;
use App\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Language>
 */
class LanguageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->lexify('Lang ????');

        return [
            'code' => fake()->unique()->lexify('x??'),
            'name' => $name,
            'speech_recognition' => SpeechRecognition::Khaya,
            'khaya_code' => null,
            'selection_words' => [strtolower($name)],
            'yes_words' => ['yes'],
            'no_words' => ['=no'],
            'number_words' => [],
            'position' => fn (): int => (int) Language::withTrashed()->max('position') + 1,
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => ['enabled' => false]);
    }

    public function keypadOnly(): static
    {
        return $this->state(fn (array $attributes): array => ['speech_recognition' => SpeechRecognition::Keypad]);
    }
}
