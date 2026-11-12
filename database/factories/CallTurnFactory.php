<?php

namespace Database\Factories;

use App\Enums\Speaker;
use App\Models\CallSession;
use App\Models\CallTurn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallTurn>
 */
class CallTurnFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'call_session_id' => CallSession::factory(),
            'speaker' => Speaker::Customer,
            'text' => fake()->sentence(),
        ];
    }
}
