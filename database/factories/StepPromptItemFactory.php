<?php

namespace Database\Factories;

use App\Enums\PromptSlot;
use App\Models\AudioClip;
use App\Models\FlowStep;
use App\Models\StepPromptItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StepPromptItem>
 */
class StepPromptItemFactory extends Factory
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
            'slot' => PromptSlot::Prompt,
            'position' => 0,
            'audio_clip_id' => AudioClip::factory(),
            'placeholder' => null,
        ];
    }
}
