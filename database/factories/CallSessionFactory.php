<?php

namespace Database\Factories;

use App\Enums\CallChannel;
use App\Enums\CallDirection;
use App\Enums\CallState;
use App\Enums\Language;
use App\Models\CallSession;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallSession>
 */
class CallSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'caller_number' => fn (array $attributes): string => Customer::query()->find($attributes['customer_id'])->phone_number ?? '233200000000',
            'channel' => CallChannel::WebRtc,
            'direction' => CallDirection::Inbound,
            'language' => Language::Twi,
            'state' => CallState::MainMenu,
            'context' => [],
        ];
    }

    public function inState(CallState $state): static
    {
        return $this->state(fn (array $attributes): array => ['state' => $state]);
    }
}
