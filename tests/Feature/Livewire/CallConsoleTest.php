<?php

use App\Livewire\CallConsole;
use App\Models\Customer;
use App\Models\User;
use App\Support\ConversationSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('renders the browser phone with customer numbers to call', function () {
    $this->actingAs(User::factory()->create());
    Customer::factory()->create(['phone_number' => '+233244000001', 'full_name' => 'Ama Mensah']);

    $this->get(route('call-console'))->assertOk()->assertSee('+233244000001')->assertSee('callConsole(', false);
});

test('switching off remembering caller languages is saved for later calls', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(CallConsole::class)
        ->assertSet('remembersLanguage', true)
        ->set('remembersLanguage', false);

    expect(app(ConversationSettings::class)->remembersLanguage())->toBeFalse();
    Livewire::test(CallConsole::class)->assertSet('remembersLanguage', false);
});

describe('calling a real phone through Twilio', function () {
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

    test('rings the number from the Twilio number and runs the flow when answered', function (bool $useDraft, string $voiceUrl) {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CA123'], 201)]);

        Livewire::test(CallConsole::class)
            ->set('useDraft', $useDraft)
            ->set('phoneToCall', '024 400 0001')
            ->call('callPhone')
            ->assertHasNoErrors();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Calls.json'
            && $request['To'] === '+233244000001'
            && $request['From'] === '+15005550006'
            && $request['Url'] === $voiceUrl
            && $request['StatusCallback'] === 'https://ivr.example.test/telephony/twilio/status');
    })->with([
        'published flow' => [false, 'https://ivr.example.test/telephony/twilio/voice'],
        'draft flow' => [true, 'https://ivr.example.test/telephony/twilio/voice?draft=1'],
    ]);

    test('shows why Twilio refused the call', function () {
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 21219, 'message' => 'The number +233244000001 is unverified.'], 400)]);

        Livewire::test(CallConsole::class)
            ->set('phoneToCall', '0244000001')
            ->call('callPhone')
            ->assertHasErrors(['phoneToCall' => 'The number +233244000001 is unverified.']);
    });

    test('does not call Twilio for an invalid number', function () {
        Livewire::test(CallConsole::class)
            ->set('phoneToCall', '0244')
            ->call('callPhone')
            ->assertHasErrors(['phoneToCall' => 'Enter a valid phone number, e.g. 0244123456.']);

        Http::assertNothingSent();
    });

    test('names the settings still missing instead of calling', function () {
        config(['telephony.providers.twilio.phone_number' => null, 'telephony.public_url' => null]);

        Livewire::test(CallConsole::class)
            ->assertSee('Set TWILIO_PHONE_NUMBER, TELEPHONY_PUBLIC_URL in .env to call phones.')
            ->set('phoneToCall', '0244000001')
            ->call('callPhone')
            ->assertHasErrors('phoneToCall');

        Http::assertNothingSent();
    });
});
