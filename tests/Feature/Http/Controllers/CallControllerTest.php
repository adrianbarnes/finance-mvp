<?php

use App\Enums\CallState;
use App\Enums\Language;
use App\Enums\LoanApplicationStatus;
use App\Enums\RegisteredVia;
use App\Models\CallSession;
use App\Models\Customer;
use App\Models\LoanApplication;
use App\Models\User;
use App\Services\Audio\AudioTranscoder;
use App\Services\Audio\PreparedAudio;
use App\Services\Speech\FakeSpeechRecognizer;
use App\Services\Speech\SpeechQuotaExceeded;
use App\Services\Speech\SpeechRecognizer;
use App\Support\ConversationSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Place a call from the browser console as a signed-in tester.
 */
function placeCall(string $callerNumber): TestResponse
{
    return test()->actingAs(User::factory()->create())->postJson(route('calls.store'), ['caller_number' => $callerNumber]);
}

/**
 * @param  array<string, string>  $input
 */
function sendCallInput(string $uuid, array $input): TestResponse
{
    return test()->postJson(route('calls.inputs.store', $uuid), $input);
}

/**
 * @return list<string>
 */
function promptKeys(TestResponse $response): array
{
    return array_values(array_filter(array_column($response->json('reply.segments'), 'key')));
}

/**
 * @return list<string>
 */
function promptLanguages(TestResponse $response): array
{
    return array_values(array_column(array_filter($response->json('reply.segments'), fn (array $segment): bool => $segment['type'] === 'prompt'), 'language'));
}

/**
 * @return list<string>
 */
function spokenTexts(TestResponse $response): array
{
    return array_values(array_column(array_filter($response->json('reply.segments'), fn (array $segment): bool => $segment['type'] === 'speech'), 'text'));
}

describe('starting a call', function () {
    test('guests are redirected to the login page', function () {
        $this->postJson(route('calls.store'), ['caller_number' => '0244000001'])->assertUnauthorized();
    });

    test('a customer with a saved language is greeted by name and asked the purpose of the call', function () {
        Customer::factory()->create(['phone_number' => '233244000001', 'full_name' => 'Ama Mensah']);

        $response = placeCall('024 400 0001');

        $response->assertCreated()->assertJsonPath('reply.state', 'main_menu')->assertJsonPath('call.customer', 'Ama Mensah');
        expect(promptKeys($response))->toBe(['greeting', 'main_menu'])
            ->and(spokenTexts($response))->toBe(['Ama Mensah']);
    });

    test('a customer without a saved language hears the language prompt', function () {
        Customer::factory()->withoutLanguage()->create(['phone_number' => '233244000001']);

        $response = placeCall('0244000001');

        $response->assertCreated()->assertJsonPath('reply.state', 'choose_language')->assertJsonPath('language', null);
        expect(promptKeys($response))->toBe(['choose_language', 'choose_language', 'choose_language'])
            ->and(promptLanguages($response))->toBe(['twi', 'ewe', 'eng']);
    });

    test('an unknown number hears the language prompt and is not linked to a customer', function () {
        $response = placeCall('0209999999');

        $response->assertCreated()->assertJsonPath('call.customer', null);
        expect(promptKeys($response))->toBe(['choose_language', 'choose_language', 'choose_language']);
        $this->assertDatabaseHas('call_sessions', ['uuid' => $response->json('call.uuid'), 'customer_id' => null, 'caller_number' => '233209999999']);
    });

    test('rejects an invalid caller number', function () {
        placeCall('12ab')->assertUnprocessable()->assertJsonValidationErrors(['caller_number' => 'Enter a valid phone number, e.g. 0244123456.']);
    });
});

describe('choosing a language', function () {
    test('saving the language when the caller says the Twi yes word', function () {
        $customer = Customer::factory()->withoutLanguage()->create(['phone_number' => '233244000001']);
        $uuid = placeCall('0244000001')->json('call.uuid');

        $response = sendCallInput($uuid, ['text' => 'Aane']);

        $response->assertOk()->assertJsonPath('reply.state', 'main_menu');
        expect(promptKeys($response))->toBe(['greeting', 'main_menu'])
            ->and($customer->fresh()->preferred_language)->toBe(Language::Twi);
    });

    test('saving the language when the caller presses its keypad number', function () {
        $customer = Customer::factory()->withoutLanguage()->create(['phone_number' => '233244000001']);
        $uuid = placeCall('0244000001')->json('call.uuid');

        sendCallInput($uuid, ['digits' => '1'])->assertJsonPath('reply.state', 'main_menu');

        expect($customer->fresh()->preferred_language)->toBe(Language::Twi);
    });

    test('thanking the caller and ending the call when the answer is not affirmative', function (array $input) {
        $uuid = placeCall('0209999999')->json('call.uuid');

        $response = sendCallInput($uuid, $input);

        $response->assertJsonPath('reply.state', 'ended')->assertJsonPath('reply.ended', true);
        expect(promptKeys($response))->toBe(['goodbye']);
        expect(CallSession::query()->where('uuid', $uuid)->first()->ended_at)->not->toBeNull();
    })->with([
        'spoken no' => [['text' => 'daabi']],
        'key that is not a language' => [['digits' => '9']],
    ]);

    test('replaying the prompt twice when there is no input, then ending the call', function () {
        $uuid = placeCall('0209999999')->json('call.uuid');

        $firstReplay = sendCallInput($uuid, ['event' => 'no_input']);
        $secondReplay = sendCallInput($uuid, ['event' => 'no_input']);
        $final = sendCallInput($uuid, ['event' => 'no_input']);

        expect(promptLanguages($firstReplay))->toBe(['twi', 'ewe', 'eng'])
            ->and(promptLanguages($secondReplay))->toBe(['twi', 'ewe', 'eng'])
            ->and(promptKeys($final))->toBe(['goodbye']);
        $final->assertJsonPath('reply.ended', true);
    });

    test('continuing in Ewe when the caller presses its keypad number', function () {
        $customer = Customer::factory()->withoutLanguage()->create(['phone_number' => '233244000001']);
        $uuid = placeCall('0244000001')->json('call.uuid');

        $response = sendCallInput($uuid, ['digits' => '2']);

        $response->assertJsonPath('reply.state', 'main_menu')->assertJsonPath('language.code', 'ewe');
        expect(promptLanguages($response))->toBe(['ewe', 'ewe'])
            ->and($customer->fresh()->preferred_language)->toBe(Language::Ewe);
    });

    test('continuing in English, recognised by the browser, when the caller says yes', function () {
        $customer = Customer::factory()->withoutLanguage()->create(['phone_number' => '233244000001']);
        $uuid = placeCall('0244000001')->json('call.uuid');

        $response = sendCallInput($uuid, ['transcript' => 'Yes']);

        $response->assertJsonPath('language.code', 'eng')->assertJsonPath('language.recognized_by_client', true);
        expect($customer->fresh()->preferred_language)->toBe(Language::English);
    });
});

describe('when remembering languages is switched off', function () {
    test('asks a customer with a saved language to choose again', function () {
        app(ConversationSettings::class)->setRemembersLanguage(false);
        Customer::factory()->create(['phone_number' => '233244000001', 'preferred_language' => Language::Twi]);

        $response = placeCall('0244000001');

        $response->assertJsonPath('reply.state', 'choose_language');
    });

    test('uses the chosen language for the call without saving it', function () {
        app(ConversationSettings::class)->setRemembersLanguage(false);
        $customer = Customer::factory()->create(['phone_number' => '233244000001', 'preferred_language' => Language::Twi]);
        $uuid = placeCall('0244000001')->json('call.uuid');

        $response = sendCallInput($uuid, ['digits' => '2']);

        $response->assertJsonPath('language.code', 'ewe');
        expect($customer->fresh()->preferred_language)->toBe(Language::Twi);
    });

    test('registers a new caller without a saved language', function () {
        app(ConversationSettings::class)->setRemembersLanguage(false);
        $uuid = placeCall('0209999999')->json('call.uuid');
        sendCallInput($uuid, ['text' => 'aane']);
        sendCallInput($uuid, ['text' => 'aane']);
        sendCallInput($uuid, ['text' => 'kwesi mensah']);

        sendCallInput($uuid, ['text' => 'aane'])->assertJsonPath('reply.state', 'main_menu');

        $this->assertDatabaseHas('customers', ['phone_number' => '233209999999', 'preferred_language' => null]);
    });
});

describe('browser-recognised speech', function () {
    test('records a browser transcript as a speech turn and answers it', function () {
        Customer::factory()->create(['phone_number' => '233244000001', 'preferred_language' => Language::English, 'balance_pesewas' => 9_900]);
        $uuid = placeCall('0244000001')->json('call.uuid');

        $response = sendCallInput($uuid, ['transcript' => 'what is my balance']);

        $response->assertJsonPath('reply.intent', 'check_balance');
        expect(spokenTexts($response))->toBe(['99 cedis']);
        $this->assertDatabaseHas('call_turns', ['input_mode' => 'speech', 'text' => 'what is my balance', 'audio_path' => null]);
    });

    test('rejects a request that sends a transcript and digits together', function () {
        Customer::factory()->create(['phone_number' => '233244000001']);
        $uuid = placeCall('0244000001')->json('call.uuid');

        sendCallInput($uuid, ['transcript' => 'yes', 'digits' => '1'])->assertUnprocessable()->assertJsonValidationErrors('transcript');
    });
});

describe('signing up an unknown caller', function () {
    test('registering the caller with the name they confirm', function () {
        $uuid = placeCall('0209999999')->json('call.uuid');
        sendCallInput($uuid, ['text' => 'aane']);
        sendCallInput($uuid, ['digits' => '1']);
        $nameResponse = sendCallInput($uuid, ['text' => 'kwesi mensah']);

        $response = sendCallInput($uuid, ['text' => 'aane']);

        expect(promptKeys($nameResponse))->toBe(['confirm_name', 'confirm_name_question'])
            ->and(spokenTexts($nameResponse))->toBe(['Kwesi Mensah'])
            ->and(promptKeys($response))->toBe(['registered', 'main_menu']);
        $this->assertDatabaseHas('customers', [
            'phone_number' => '233209999999',
            'full_name' => 'Kwesi Mensah',
            'preferred_language' => Language::Twi->value,
            'balance_pesewas' => 0,
            'registered_via' => RegisteredVia::Voice->value,
        ]);
    });

    test('asking for the name again when the caller rejects it', function () {
        $uuid = placeCall('0209999999')->json('call.uuid');
        sendCallInput($uuid, ['text' => 'aane']);
        sendCallInput($uuid, ['text' => 'aane']);
        sendCallInput($uuid, ['text' => 'kwesi']);

        $response = sendCallInput($uuid, ['text' => 'daabi']);

        $response->assertJsonPath('reply.state', 'ask_name');
        $this->assertDatabaseCount('customers', 0);
    });

    test('ending the call without registering when the caller declines', function () {
        $uuid = placeCall('0209999999')->json('call.uuid');
        sendCallInput($uuid, ['text' => 'aane']);

        $response = sendCallInput($uuid, ['digits' => '2']);

        $response->assertJsonPath('reply.state', 'ended');
        expect(promptKeys($response))->toBe(['goodbye']);
        $this->assertDatabaseCount('customers', 0);
    });
});

describe('self-service actions', function () {
    test('reading out the balance in English', function () {
        Customer::factory()->create(['phone_number' => '233244000001', 'balance_pesewas' => 125_050]);
        $uuid = placeCall('0244000001')->json('call.uuid');

        $response = sendCallInput($uuid, ['text' => 'me pɛ sɛ mehwɛ me sika dodoɔ']);

        $response->assertJsonPath('reply.intent', 'check_balance')->assertJsonPath('reply.state', 'anything_else');
        expect(promptKeys($response))->toBe(['balance_is', 'anything_else'])
            ->and(spokenTexts($response))->toBe(['1,250 cedis 50 pesewas']);
    });

    test('taking a loan application spoken with English amounts', function () {
        $customer = Customer::factory()->create(['phone_number' => '233244000001']);
        $uuid = placeCall('0244000001')->json('call.uuid');
        sendCallInput($uuid, ['text' => 'me pɛ bosea']);
        sendCallInput($uuid, ['text' => 'one thousand five hundred cedis']);
        $confirmation = sendCallInput($uuid, ['text' => 'asram asia']);

        $response = sendCallInput($uuid, ['text' => 'aane']);

        expect(spokenTexts($confirmation))->toBe(['1,500 cedis for 6 months']);
        $application = LoanApplication::query()->sole();
        expect($application->customer_id)->toBe($customer->id)
            ->and($application->amount_pesewas)->toBe(150_000)
            ->and($application->term_months)->toBe(6)
            ->and($application->status)->toBe(LoanApplicationStatus::Pending)
            ->and($application->call_session_id)->toBe(CallSession::query()->where('uuid', $uuid)->value('id'));
        expect(promptKeys($response))->toBe(['application_submitted', 'anything_else'])
            ->and(spokenTexts($response))->toBe([implode(' ', str_split($application->reference))]);
    });

    test('taking the loan amount from keypad digits ending with hash', function () {
        Customer::factory()->create(['phone_number' => '233244000001']);
        $uuid = placeCall('0244000001')->json('call.uuid');
        sendCallInput($uuid, ['digits' => '2']);

        $response = sendCallInput($uuid, ['digits' => '2500#']);

        $response->assertJsonPath('reply.state', 'apply_term');
        expect(CallSession::query()->where('uuid', $uuid)->first()->contextValue('amount_pesewas'))->toBe(250_000);
    });

    test('asking for the amount again when it is outside the allowed range', function () {
        Customer::factory()->create(['phone_number' => '233244000001']);
        $uuid = placeCall('0244000001')->json('call.uuid');
        sendCallInput($uuid, ['digits' => '2']);

        $response = sendCallInput($uuid, ['text' => 'fifty']);

        $response->assertJsonPath('reply.state', 'apply_amount');
        expect(promptKeys($response))->toBe(['amount_out_of_range', 'ask_amount'])
            ->and(spokenTexts($response))->toBe(['100 cedis to 10,000 cedis']);
    });

    test('cancelling the application when the caller does not confirm', function () {
        Customer::factory()->create(['phone_number' => '233244000001']);
        $uuid = placeCall('0244000001')->json('call.uuid');
        sendCallInput($uuid, ['digits' => '2']);
        sendCallInput($uuid, ['digits' => '1000#']);
        sendCallInput($uuid, ['digits' => '3']);

        $response = sendCallInput($uuid, ['text' => 'daabi']);

        expect(promptKeys($response))->toBe(['application_cancelled', 'main_menu']);
        $this->assertDatabaseCount('loan_applications', 0);
    });

    test('reading out the status of the latest application', function () {
        $customer = Customer::factory()->create(['phone_number' => '233244000001']);
        LoanApplication::factory()->for($customer)->create(['status' => LoanApplicationStatus::Pending, 'created_at' => now()->subWeek()]);
        LoanApplication::factory()->for($customer)->create(['status' => LoanApplicationStatus::Approved, 'reference' => 'LN0042', 'amount_pesewas' => 200_000]);
        $uuid = placeCall('0244000001')->json('call.uuid');

        $response = sendCallInput($uuid, ['digits' => '3']);

        expect(promptKeys($response))->toBe(['application_status_intro', 'status_approved', 'anything_else'])
            ->and(spokenTexts($response))->toBe(['2,000 cedis, reference L N 0 0 4 2']);
    });

    test('reading out the next repayment of a disbursed loan', function () {
        $this->travelTo('2026-11-12 09:00:00');
        $customer = Customer::factory()->create(['phone_number' => '233244000001']);
        LoanApplication::factory()->for($customer)->disbursed()->create(['next_repayment_pesewas' => 36_500, 'next_repayment_due_on' => '2026-11-22']);
        $uuid = placeCall('0244000001')->json('call.uuid');

        $response = sendCallInput($uuid, ['digits' => '4']);

        expect(promptKeys($response))->toBe(['repayment_due_is', 'repayment_due_on', 'anything_else'])
            ->and(spokenTexts($response))->toBe(['365 cedis', 'Sunday, 22 November 2026']);
    });

    test('telling the caller when no repayment is due', function () {
        $customer = Customer::factory()->create(['phone_number' => '233244000001']);
        LoanApplication::factory()->for($customer)->create(['status' => LoanApplicationStatus::Pending]);
        $uuid = placeCall('0244000001')->json('call.uuid');

        $response = sendCallInput($uuid, ['digits' => '4']);

        expect(promptKeys($response))->toBe(['no_repayment_due', 'anything_else']);
    });

    test('telling the caller agents are not available yet', function () {
        Customer::factory()->create(['phone_number' => '233244000001']);
        $uuid = placeCall('0244000001')->json('call.uuid');

        $response = sendCallInput($uuid, ['text' => 'I want to speak to an agent']);

        $response->assertJsonPath('reply.state', 'main_menu');
        expect(promptKeys($response))->toBe(['agent_unavailable', 'main_menu']);
    });

    test('ending the call when the caller has nothing else to ask', function () {
        Customer::factory()->create(['phone_number' => '233244000001']);
        $uuid = placeCall('0244000001')->json('call.uuid');
        sendCallInput($uuid, ['digits' => '1']);

        $response = sendCallInput($uuid, ['text' => 'daabi, medaase']);

        $response->assertJsonPath('reply.ended', true);
        expect(promptKeys($response))->toBe(['goodbye']);
    });
});

describe('spoken utterances', function () {
    test('transcribing the cleaned-up audio in the call language and answering', function () {
        Storage::fake('local');
        Customer::factory()->create(['phone_number' => '233244000001', 'balance_pesewas' => 5_000]);
        $recognizer = new FakeSpeechRecognizer(['me sika dodoɔ']);
        $this->app->instance(SpeechRecognizer::class, $recognizer);
        $this->mock(AudioTranscoder::class)->shouldReceive('prepareForRecognition')->once()->andReturn(new PreparedAudio('clean-wav', 1.4));
        $uuid = placeCall('0244000001')->json('call.uuid');

        $response = $this->post(route('calls.utterances.store', $uuid), ['audio' => UploadedFile::fake()->createWithContent('utterance.wav', 'RIFF-raw-audio')->mimeType('audio/wav')], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('reply.heard', 'me sika dodoɔ')->assertJsonPath('reply.intent', 'check_balance');
        expect($recognizer->received)->toBe([['mime_type' => 'audio/wav', 'language' => Language::Twi, 'bytes' => 9]]);
        $turn = CallSession::query()->where('uuid', $uuid)->first()->turns()->whereNotNull('audio_path')->sole();
        Storage::disk('local')->assertExists($turn->audio_path);
    });

    test('treating audio without speech as no input without calling the recognizer', function () {
        Storage::fake('local');
        Customer::factory()->create(['phone_number' => '233244000001']);
        $recognizer = new FakeSpeechRecognizer(['should not be used']);
        $this->app->instance(SpeechRecognizer::class, $recognizer);
        $this->mock(AudioTranscoder::class)->shouldReceive('prepareForRecognition')->andReturn(new PreparedAudio('', 0.0));
        $uuid = placeCall('0244000001')->json('call.uuid');

        $response = $this->post(route('calls.utterances.store', $uuid), ['audio' => UploadedFile::fake()->createWithContent('utterance.wav', 'RIFF')->mimeType('audio/wav')], ['Accept' => 'application/json']);

        expect(promptKeys($response))->toBe(['main_menu'])
            ->and($recognizer->received)->toBe([]);
    });

    test('asking for the keypad when the speech quota is used up', function () {
        Storage::fake('local');
        Customer::factory()->create(['phone_number' => '233244000001']);
        $this->app->instance(SpeechRecognizer::class, new FakeSpeechRecognizer([new SpeechQuotaExceeded(SpeechQuotaExceeded::Monthly)]));
        $this->mock(AudioTranscoder::class)->shouldReceive('prepareForRecognition')->andReturn(new PreparedAudio('clean-wav', 1.0));
        $uuid = placeCall('0244000001')->json('call.uuid');

        $response = $this->post(route('calls.utterances.store', $uuid), ['audio' => UploadedFile::fake()->createWithContent('utterance.wav', 'RIFF')->mimeType('audio/wav')], ['Accept' => 'application/json']);

        $response->assertJsonPath('reply.suggest_keypad', true)->assertJsonPath('reply.state', 'main_menu');
        expect(promptKeys($response))->toBe(['speech_unavailable', 'main_menu']);
    });

    test('rejects an upload that is not audio', function () {
        Customer::factory()->create(['phone_number' => '233244000001']);
        $uuid = placeCall('0244000001')->json('call.uuid');

        $response = $this->post(route('calls.utterances.store', $uuid), ['audio' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], ['Accept' => 'application/json']);

        $response->assertUnprocessable()->assertJsonValidationErrors('audio');
    });
});

describe('ended calls', function () {
    test('returns 409 when input is sent to a call that has ended', function () {
        $call = CallSession::factory()->inState(CallState::Ended)->create(['ended_at' => now()]);

        $this->actingAs(User::factory()->create())->postJson(route('calls.inputs.store', $call), ['digits' => '1'])->assertConflict();
    });

    test('hanging up ends the call', function () {
        $call = CallSession::factory()->create();

        $this->actingAs(User::factory()->create())->deleteJson(route('calls.destroy', $call))->assertNoContent();

        expect($call->fresh()->state)->toBe(CallState::Ended)
            ->and($call->fresh()->ended_at)->not->toBeNull();
    });
});
