<?php

namespace Database\Factories;

use App\Enums\RegisteredVia;
use App\Models\Customer;
use App\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'phone_number' => '+23324'.fake()->unique()->numerify('#######'),
            'preferred_language_id' => fn (): ?int => Language::findByCode('twi')?->id,
            'balance_pesewas' => fake()->numberBetween(0, 500_000),
            'registered_via' => RegisteredVia::Panel,
        ];
    }

    /**
     * A customer whose saved language has this code, e.g. "ewe".
     */
    public function speaking(string $code): static
    {
        return $this->state(fn (array $attributes): array => ['preferred_language_id' => Language::findByCode($code)?->id]);
    }

    /**
     * A customer who has not chosen a language yet (or had it reset).
     */
    public function withoutLanguage(): static
    {
        return $this->state(fn (array $attributes): array => ['preferred_language_id' => null]);
    }
}
