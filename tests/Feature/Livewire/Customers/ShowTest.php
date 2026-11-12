<?php

use App\Enums\LoanApplicationStatus;
use App\Livewire\Customers\Show;
use App\Models\Customer;
use App\Models\LoanApplication;
use App\Models\User;
use Livewire\Livewire;

test('updates the customer balance', function () {
    $this->actingAs(User::factory()->create());
    $customer = Customer::factory()->create(['balance_pesewas' => 0]);

    Livewire::test(Show::class, ['customer' => $customer])
        ->set('balance', '1250.50')
        ->call('saveDetails')
        ->assertHasNoErrors();

    expect($customer->fresh()->balance_pesewas)->toBe(125_050);
});

test('resets the saved language so the next call asks again', function () {
    $this->actingAs(User::factory()->create());
    $customer = Customer::factory()->create();

    Livewire::test(Show::class, ['customer' => $customer])->call('resetLanguage');

    expect($customer->fresh()->preferred_language)->toBeNull();
});

test('updates the status and next repayment of a loan application', function () {
    $this->actingAs(User::factory()->create());
    $customer = Customer::factory()->create();
    $application = LoanApplication::factory()->for($customer)->create(['amount_pesewas' => 100_000, 'term_months' => 6]);

    Livewire::test(Show::class, ['customer' => $customer])
        ->call('editApplication', $application->id)
        ->set('applicationStatus', LoanApplicationStatus::Disbursed->value)
        ->set('nextRepaymentAmount', '180')
        ->set('nextRepaymentDueOn', '2026-12-01')
        ->call('saveApplication')
        ->assertHasNoErrors();

    $application->refresh();
    expect($application->status)->toBe(LoanApplicationStatus::Disbursed)
        ->and($application->next_repayment_pesewas)->toBe(18_000)
        ->and($application->next_repayment_due_on->toDateString())->toBe('2026-12-01');
});

test('requires a due date when a next repayment amount is entered', function () {
    $this->actingAs(User::factory()->create());
    $customer = Customer::factory()->create();

    Livewire::test(Show::class, ['customer' => $customer])
        ->call('newApplication')
        ->set('applicationAmount', '500')
        ->set('applicationTerm', '3')
        ->set('nextRepaymentAmount', '180')
        ->call('saveApplication')
        ->assertHasErrors(['nextRepaymentDueOn' => 'required_with']);
});

test('cannot edit another customer\'s loan application', function () {
    $this->actingAs(User::factory()->create());
    $customer = Customer::factory()->create();
    $otherApplication = LoanApplication::factory()->create();

    Livewire::test(Show::class, ['customer' => $customer])
        ->call('editApplication', $otherApplication->id)
        ->assertNotFound();
});
