<?php

namespace Database\Factories;

use App\Enums\LoanApplicationStatus;
use App\Models\Customer;
use App\Models\LoanApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanApplication>
 */
class LoanApplicationFactory extends Factory
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
            'amount_pesewas' => fake()->numberBetween(100, 10_000) * 100,
            'term_months' => fake()->numberBetween(1, 12),
            'status' => LoanApplicationStatus::Pending,
        ];
    }

    /**
     * A disbursed loan with an upcoming repayment.
     */
    public function disbursed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LoanApplicationStatus::Disbursed,
            'next_repayment_pesewas' => 25_000,
            'next_repayment_due_on' => now()->addWeeks(2)->toDateString(),
        ]);
    }
}
