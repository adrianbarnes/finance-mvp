<?php

use App\Enums\LoanApplicationStatus;
use App\Livewire\Customers\Show;
use App\Models\Customer;
use App\Models\LoanApplication;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    expect($customer->fresh()->preferred_language_id)->toBeNull();
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

describe('calling the customer', function () {
    beforeEach(function () {
        config([
            'telephony.default' => 'twilio',
            'telephony.public_url' => 'https://ivr.example.test',
            'telephony.providers.twilio.account_sid' => 'AC123',
            'telephony.providers.twilio.auth_token' => 'secret',
            'telephony.providers.twilio.phone_number' => '+15005550006',
        ]);
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
    });

    test('rings the customer\'s number with the published or draft flow', function (array $arguments, string $voiceUrl) {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA123'], 201)]);
        $customer = Customer::factory()->create(['phone_number' => '+233244000001']);

        Livewire::test(Show::class, ['customer' => $customer])->call('callPhone', ...$arguments);

        Http::assertSent(fn (Request $request): bool => $request['To'] === '+233244000001' && $request['Url'] === $voiceUrl);
    })->with([
        'published flow' => [[], 'https://ivr.example.test/telephony/twilio/voice'],
        'draft flow' => [[true], 'https://ivr.example.test/telephony/twilio/voice?draft=1'],
    ]);

    test('says why the call could not be placed', function () {
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'The number +233244000001 is unverified.'], 400)]);
        $customer = Customer::factory()->create(['phone_number' => '+233244000001']);

        Livewire::test(Show::class, ['customer' => $customer])
            ->call('callPhone')
            ->assertDispatched('toast-show', fn (string $event, array $params): bool => str_contains(json_encode($params), 'is unverified'));
    });
});
