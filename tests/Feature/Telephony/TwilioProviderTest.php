<?php

use App\Enums\CallChannel;
use App\Enums\CallDirection;
use App\Models\CallSession;
use App\Models\Customer;
use App\Services\Audio\AudioTranscoder;
use App\Services\Audio\PreparedAudio;
use App\Services\Flows\FlowPublisher;
use App\Services\Speech\FakeSpeechRecognizer;
use App\Services\Speech\SpeechRecognizer;
use App\Services\Telephony\Twilio\TwilioProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

const TwilioPublicUrl = 'https://ivr.example.test';
const TwilioAuthToken = 'test-auth-token';
const TwilioCallSid = 'CA0000000000000000000000000000test';

beforeEach(function () {
    config([
        'telephony.public_url' => TwilioPublicUrl,
        'telephony.providers.twilio.auth_token' => TwilioAuthToken,
        'telephony.providers.twilio.account_sid' => 'AC-test',
        'telephony.providers.twilio.validate_signature' => true,
    ]);
});

/**
 * Send a webhook signed the way Twilio signs it.
 *
 * @param  array<string, string>  $parameters
 */
function twilioWebhook(string $path, array $parameters = []): TestResponse
{
    $parameters = ['CallSid' => TwilioCallSid, ...$parameters];
    $signature = TwilioProvider::signature(TwilioPublicUrl.$path, $parameters, TwilioAuthToken);

    return test()->post($path, $parameters, ['X-Twilio-Signature' => $signature]);
}

function twilioCall(string $from = '+233244000001', string $query = ''): TestResponse
{
    return twilioWebhook(route('telephony.voice', ['provider' => 'twilio'], absolute: false).$query, ['From' => $from, 'To' => '+15550000000']);
}

function twilioAnswer(CallSession $call, array $parameters = [], string $query = ''): TestResponse
{
    return twilioWebhook(route('telephony.calls.answer', ['provider' => 'twilio', 'call' => $call], absolute: false).$query, $parameters);
}

function twilioSession(): CallSession
{
    return CallSession::query()->where('external_call_id', TwilioCallSid)->sole();
}

function twiml(TestResponse $response): SimpleXMLElement
{
    $response->assertOk()->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

    return simplexml_load_string((string) $response->getContent());
}

/**
 * @return list<string>
 */
function playedUrls(SimpleXMLElement $twiml): array
{
    return array_map('strval', $twiml->xpath('//Play'));
}

describe('request signatures', function () {
    test('rejects a webhook without a valid Twilio signature', function (array $headers) {
        $this->post(route('telephony.voice', ['provider' => 'twilio']), ['CallSid' => TwilioCallSid, 'From' => '+233244000001'], $headers)->assertForbidden();

        expect(CallSession::query()->count())->toBe(0);
    })->with([
        'no signature' => [[]],
        'wrong signature' => [['X-Twilio-Signature' => 'bm90LXRoZS1yaWdodC1vbmU=']],
    ]);

    test('rejects every webhook when no auth token is set', function () {
        config(['telephony.providers.twilio.auth_token' => null]);

        $this->post(route('telephony.voice', ['provider' => 'twilio']), ['CallSid' => TwilioCallSid], ['X-Twilio-Signature' => 'anything'])->assertForbidden();
    });
});

describe('an incoming call', function () {
    test('plays the published flow from its telephony audio and records a Twi answer', function () {
        Customer::factory()->create(['phone_number' => '+233244000001', 'full_name' => 'Ama Mensah']);

        $twiml = twiml(twilioCall());

        $call = twilioSession();
        expect($call->channel)->toBe(CallChannel::Ivr)
            ->and($call->callFlow->version)->toBe(app(FlowPublisher::class)->live()->version)
            ->and($call->state)->toBe('choice');
        expect(playedUrls($twiml))->not->toBeEmpty()
            ->each->toStartWith(TwilioPublicUrl.'/telephony/audio/flow-audio/')
            ->and((string) $twiml->Say)->toBe('Ama Mensah');
        expect(playedUrls($twiml)[0])->toEndWith('.wav');
        expect((string) $twiml->Record['action'])->toBe(TwilioPublicUrl.'/telephony/twilio/calls/'.$call->uuid.'/answer')
            ->and((string) $twiml->Record['finishOnKey'])->toBe('0123456789*#');
    });

    test('runs the draft flow when the webhook asks for it', function () {
        $draft = app(FlowPublisher::class)->draft();

        twiml(twilioCall(query: '?draft=1'));

        expect(twilioSession()->call_flow_id)->toBe($draft->id);
    });

    test('gathers keys and English speech for a caller whose language Twilio recognises', function () {
        Customer::factory()->speaking('eng')->create(['phone_number' => '+233244000001']);

        $twiml = twiml(twilioCall());

        expect((string) $twiml->Gather['input'])->toBe('dtmf speech')
            ->and((string) $twiml->Gather['language'])->toBe('en-GB')
            ->and((string) $twiml->Gather['numDigits'])->toBe('1')
            ->and($twiml->Gather->Play)->not->toBeEmpty()
            ->and((string) $twiml->Redirect)->toBe(TwilioPublicUrl.'/telephony/twilio/calls/'.twilioSession()->uuid.'/no-input');
    });
});

describe('a call placed from the call console', function () {
    test('treats the number called as the customer', function () {
        Customer::factory()->create(['phone_number' => '+233244000001', 'full_name' => 'Ama Mensah']);

        $twiml = twiml(twilioWebhook(route('telephony.voice', ['provider' => 'twilio'], absolute: false), ['From' => '+15005550006', 'To' => '+233244000001', 'Direction' => 'outbound-api']));

        expect((string) $twiml->Say)->toBe('Ama Mensah')
            ->and(twilioSession()->direction)->toBe(CallDirection::Outbound)
            ->and(twilioSession()->caller_number)->toBe('+233244000001');
    });
});

describe('answers', function () {
    test('a key that ends the recording answers the menu', function () {
        Customer::factory()->create(['phone_number' => '+233244000001']);
        twilioCall();

        twiml(twilioAnswer(twilioSession(), ['Digits' => '2', 'RecordingUrl' => 'https://api.twilio.com/recording', 'RecordingDuration' => '1']));

        expect(twilioSession()->currentStep->name)->toBe('Loan amount');
    });

    test('a first key on a number question collects the rest of the number', function () {
        Customer::factory()->create(['phone_number' => '+233244000001']);
        twilioCall();
        twilioAnswer(twilioSession(), ['Digits' => '2']);

        $twiml = twiml(twilioAnswer(twilioSession(), ['Digits' => '2', 'RecordingUrl' => 'https://api.twilio.com/recording']));

        expect((string) $twiml->Gather['finishOnKey'])->toBe('#')
            ->and((string) $twiml->Gather['action'])->toBe(TwilioPublicUrl.'/telephony/twilio/calls/'.twilioSession()->uuid.'/answer?typed=2');

        twiml(twilioAnswer(twilioSession(), ['Digits' => '500'], '?typed=2'));

        expect(twilioSession()->contextValue('answers.amount'))->toEqual(2500)
            ->and(twilioSession()->currentStep->name)->toBe('Loan term (months)');
    });

    test('a recording is fetched from Twilio and sent to Khaya in the call language', function () {
        config(['cache.default' => 'database']);
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake(['api.twilio.com/*' => Http::response('RIFF-caller-audio')]);
        $recognizer = new FakeSpeechRecognizer(['me sika dodoɔ']);
        $this->app->instance(SpeechRecognizer::class, $recognizer);
        $this->mock(AudioTranscoder::class)->shouldReceive('prepareForRecognition')->once()->with('RIFF-caller-audio')->andReturn(new PreparedAudio('clean-wav', 1.2));
        Customer::factory()->create(['phone_number' => '+233244000001', 'balance_pesewas' => 5_000]);
        twilioCall();

        $hold = twiml(twilioAnswer(twilioSession(), ['RecordingUrl' => 'https://api.twilio.com/2010-04-01/Accounts/AC-test/Recordings/RE1', 'RecordingDuration' => '2']));

        $resultUrl = (string) $hold->Redirect;
        expect($hold->Pause)->toHaveCount(1)
            ->and($resultUrl)->toStartWith(TwilioPublicUrl.'/telephony/twilio/calls/'.twilioSession()->uuid.'/result?');

        $twiml = twiml(twilioWebhook(substr($resultUrl, strlen(TwilioPublicUrl))));

        expect($recognizer->received)->toBe([['mime_type' => 'audio/wav', 'language' => 'twi', 'bytes' => 9]])
            ->and((string) $twiml->Say)->toBe('50 cedis');
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization'));
    });

    test('the caller stays on hold until slow speech recognition has finished', function () {
        Customer::factory()->create(['phone_number' => '+233244000001']);
        twilioCall();
        $path = route('telephony.calls.result', ['provider' => 'twilio', 'call' => twilioSession(), 'ticket' => 'not-ready-yet', 'attempt' => 3], absolute: false);

        $twiml = twiml(twilioWebhook($path));

        expect($twiml->Pause)->toHaveCount(1)
            ->and((string) $twiml->Redirect)->toContain('attempt=4')
            ->and(twilioSession()->hasEnded())->toBeFalse();
    });

    test('a call on hold too long is hung up', function () {
        Customer::factory()->create(['phone_number' => '+233244000001']);
        twilioCall();
        $path = route('telephony.calls.result', ['provider' => 'twilio', 'call' => twilioSession(), 'ticket' => 'never-ready', 'attempt' => 60], absolute: false);

        $twiml = twiml(twilioWebhook($path));

        expect($twiml->Hangup)->toHaveCount(1)
            ->and(twilioSession()->hasEnded())->toBeTrue();
    });

    test('a recording link that is not hosted by Twilio is not fetched', function () {
        Http::preventStrayRequests();
        Customer::factory()->create(['phone_number' => '+233244000001']);
        twilioCall();

        twiml(twilioAnswer(twilioSession(), ['RecordingUrl' => 'https://attacker.example/recording']));

        Http::assertNothingSent();
        expect(twilioSession()->turns()->where('input_mode', 'no_input')->count())->toBe(1);
    });

    test('Twilio speech recognition answers an English caller', function () {
        Customer::factory()->speaking('eng')->create(['phone_number' => '+233244000001', 'balance_pesewas' => 9_900]);
        twilioCall();

        $twiml = twiml(twilioAnswer(twilioSession(), ['SpeechResult' => 'what is my balance', 'Confidence' => '0.9']));

        expect((string) $twiml->Gather->Say)->toBe('99 cedis');
    });

    test('silence after a gather counts as no input and asks again', function () {
        Customer::factory()->speaking('eng')->create(['phone_number' => '+233244000001']);
        twilioCall();

        twiml(twilioWebhook(route('telephony.calls.no-input', ['provider' => 'twilio', 'call' => twilioSession()], absolute: false)));

        expect(twilioSession()->turns()->where('input_mode', 'no_input')->count())->toBe(1)
            ->and(twilioSession()->currentStep->name)->toBe('Main menu');
    });

    test('a call that ends hangs up after its goodbye', function () {
        Customer::factory()->create(['phone_number' => '+233244000001']);
        twilioCall();

        $twiml = twiml(twilioAnswer(twilioSession(), ['Digits' => '0']));

        expect($twiml->Hangup)->toHaveCount(1)
            ->and(twilioSession()->hasEnded())->toBeTrue();
    });

    test('a webhook for another Twilio call is not found', function () {
        Customer::factory()->create(['phone_number' => '+233244000001']);
        twilioCall();
        $parameters = ['CallSid' => 'CA-someone-else', 'Digits' => '1'];
        $path = route('telephony.calls.answer', ['provider' => 'twilio', 'call' => twilioSession()], absolute: false);

        $this->post($path, $parameters, ['X-Twilio-Signature' => TwilioProvider::signature(TwilioPublicUrl.$path, $parameters, TwilioAuthToken)])->assertNotFound();
    });
});

test('the status callback ends the call when the caller hangs up', function () {
    Customer::factory()->create(['phone_number' => '+233244000001']);
    twilioCall();

    twilioWebhook(route('telephony.status', ['provider' => 'twilio'], absolute: false), ['CallStatus' => 'completed'])->assertNoContent();

    expect(twilioSession()->hasEnded())->toBeTrue();
});

test('a provider that is not configured has no webhooks', function () {
    $this->post('/telephony/plivo/voice', ['CallSid' => TwilioCallSid])->assertNotFound();
});

test('a call placed through another provider is not found on this one', function () {
    Customer::factory()->create(['phone_number' => '+233244000001']);
    twilioCall();
    twilioSession()->update(['telephony_provider' => 'another']);

    twilioAnswer(twilioSession(), ['Digits' => '1'])->assertNotFound();
});
