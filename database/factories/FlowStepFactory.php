<?php

namespace Database\Factories;

use App\Enums\StepType;
use App\Models\CallFlow;
use App\Models\FlowStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlowStep>
 */
class FlowStepFactory extends Factory
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
            'parent_id' => null,
            'position' => 0,
            'type' => StepType::Message,
            'name' => fake()->words(2, true),
            'config' => [],
        ];
    }

    public function ofType(StepType $type): static
    {
        return $this->state(fn (array $attributes): array => ['type' => $type, 'name' => $type->label()]);
    }

    /**
     * A branch under the given choice or check.
     */
    public function branchOf(FlowStep $parent, ?string $keypadDigit = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'call_flow_id' => $parent->call_flow_id,
            'parent_id' => $parent->id,
            'type' => StepType::Branch,
            'keypad_digit' => $keypadDigit,
        ]);
    }
}
