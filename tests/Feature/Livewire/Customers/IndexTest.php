<?php

use App\Enums\RegisteredVia;
use App\Livewire\Customers\Index;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('customers.index'))->assertRedirect(route('login'));
});

test('adds a customer with a normalised phone number and opening balance', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Index::class)
        ->set('fullName', 'Abena Boateng')
        ->set('phoneNumber', '024 455 6677')
        ->set('preferredLanguage', 'twi')
        ->set('openingBalance', '150.25')
        ->call('addCustomer')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('customers', [
        'full_name' => 'Abena Boateng',
        'phone_number' => '+233244556677',
        'preferred_language_id' => language('twi')->id,
        'balance_pesewas' => 15_025,
        'registered_via' => RegisteredVia::Panel->value,
    ]);
});

test('accepts a foreign number entered with its country code', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Index::class)
        ->set('phoneNumber', '+44 7911 123456')
        ->set('openingBalance', '0')
        ->call('addCustomer')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('customers', ['phone_number' => '+447911123456']);
});

test('rejects a number that is not a valid phone number', function (string $phoneNumber) {
    $this->actingAs(User::factory()->create());

    Livewire::test(Index::class)
        ->set('phoneNumber', $phoneNumber)
        ->set('openingBalance', '0')
        ->call('addCustomer')
        ->assertHasErrors(['phoneNumber' => 'Enter a valid phone number, e.g. 0244123456 or +233244123456.']);

    expect(Customer::query()->count())->toBe(0);
})->with([
    'too short' => ['0244'],
    'letters' => ['02ab123456'],
    'unassigned Ghana prefix' => ['0144123456'],
]);

test('rejects a phone number that is already registered', function () {
    $this->actingAs(User::factory()->create());
    Customer::factory()->create(['phone_number' => '+233244556677']);

    Livewire::test(Index::class)
        ->set('phoneNumber', '0244556677')
        ->call('addCustomer')
        ->assertHasErrors(['phoneNumber' => 'unique']);
});

test('lists customers and filters them by phone number', function () {
    $this->actingAs(User::factory()->create());
    Customer::factory()->create(['full_name' => 'Ama Mensah', 'phone_number' => '+233244000001']);
    Customer::factory()->create(['full_name' => 'Kofi Asare', 'phone_number' => '+233555000002']);

    Livewire::test(Index::class)
        ->set('search', '0244000001')
        ->assertSee('Ama Mensah')
        ->assertDontSee('Kofi Asare')
        ->set('search', '0555')
        ->assertSee('Kofi Asare')
        ->assertDontSee('Ama Mensah');
});
