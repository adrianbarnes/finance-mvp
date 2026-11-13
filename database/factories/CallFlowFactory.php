<?php

namespace Database\Factories;

use App\Enums\FlowStatus;
use App\Models\CallFlow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallFlow>
 */
class CallFlowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version' => fn (): int => (int) CallFlow::query()->max('version') + 1,
            'status' => FlowStatus::Draft,
            'settings' => [],
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => FlowStatus::Published, 'published_at' => now()]);
    }
}
