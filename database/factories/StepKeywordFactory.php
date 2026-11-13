<?php

namespace Database\Factories;

use App\Models\FlowStep;
use App\Models\Language;
use App\Models\StepKeyword;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StepKeyword>
 */
class StepKeywordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'flow_step_id' => FlowStep::factory(),
            'language_id' => fn (): ?int => Language::findByCode('twi')?->id,
            'words' => [fake()->word()],
        ];
    }
}
