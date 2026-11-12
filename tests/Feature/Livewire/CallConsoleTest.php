<?php

use App\Livewire\CallConsole;
use App\Models\Customer;
use App\Models\User;
use App\Support\ConversationSettings;
use Livewire\Livewire;

test('renders the browser phone with customer numbers to call', function () {
    $this->actingAs(User::factory()->create());
    Customer::factory()->create(['phone_number' => '233244000001', 'full_name' => 'Ama Mensah']);

    $this->get(route('call-console'))->assertOk()->assertSee('233244000001')->assertSee('callConsole(', false);
});

test('switching off remembering caller languages is saved for later calls', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(CallConsole::class)
        ->assertSet('remembersLanguage', true)
        ->set('remembersLanguage', false);

    expect(app(ConversationSettings::class)->remembersLanguage())->toBeFalse();
    Livewire::test(CallConsole::class)->assertSet('remembersLanguage', false);
});
