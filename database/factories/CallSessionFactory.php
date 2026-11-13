<?php

namespace Database\Factories;

use App\Enums\CallChannel;
use App\Enums\CallDirection;
use App\Models\CallSession;
use App\Models\Customer;
use App\Models\Language;
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
            'language_id' => fn (): ?int => Language::findByCode('twi')?->id,
            'state' => 'choice',
            'context' => [],
        ];
    }

    /**
     * A call in the language with this code, e.g. "ewe".
     */
    public function speaking(string $code): static
    {
        return $this->state(fn (array $attributes): array => ['language_id' => Language::findByCode($code)?->id]);
    }

    public function withoutLanguage(): static
    {
        return $this->state(fn (array $attributes): array => ['language_id' => null]);
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes): array => ['state' => 'ended', 'current_step_id' => null, 'ended_at' => now()]);
    }
}
