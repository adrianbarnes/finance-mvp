<?php

use App\Services\Abena\AbenaTextToSpeech;
use App\Services\Speech\TextToSpeechFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config(['services.abena.url' => 'https://abena.test/api/v1', 'services.abena.speed' => 1.0]);
});

test('returns the decoded WAV and sends the API key as a bearer token', function () {
    config(['services.abena.key' => 'abena-key']);
    Http::preventStrayRequests();
    Http::fake(['https://abena.test/api/v1/tts/synthesize/' => Http::response(['status' => 'success', 'audio_base64' => base64_encode('RIFF-wav')])]);

    $audio = app(AbenaTextToSpeech::class)->synthesize('Akwaaba', 'abena_twi_high');

    expect($audio)->toBe('RIFF-wav');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer abena-key')
        && $request['text'] === 'Akwaaba'
        && $request['voice'] === 'abena_twi_high');
});

test('uses the free tier without an Authorization header when no key is set', function () {
    config(['services.abena.key' => null]);
    Http::preventStrayRequests();
    Http::fake(['https://abena.test/api/v1/tts/synthesize/' => Http::response(['status' => 'success', 'audio_base64' => base64_encode('RIFF')])]);

    app(AbenaTextToSpeech::class)->synthesize('Akwaaba', 'abena_twi_high');

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

test('reports exhausted credits from a 402 response', function () {
    Http::preventStrayRequests();
    Http::fake(['https://abena.test/api/v1/tts/synthesize/' => Http::response(['message' => 'Credits used'], 402)]);

    expect(fn () => app(AbenaTextToSpeech::class)->synthesize('Akwaaba', 'abena_twi_high'))
        ->toThrow(fn (TextToSpeechFailed $exception) => expect($exception->isAllowanceExhausted())->toBeTrue()
            ->and($exception->getMessage())->toBe('Credits used'));
});

test('rejects text longer than the 500 character limit without a request', function () {
    Http::preventStrayRequests();

    expect(fn () => app(AbenaTextToSpeech::class)->synthesize(str_repeat('a', 501), 'abena_twi_high'))->toThrow(TextToSpeechFailed::class);

    Http::assertNothingSent();
});

test('waits for Retry-After and retries when the voice service is at capacity', function () {
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fakeSequence('https://abena.test/api/v1/tts/synthesize/')
        ->push(['message' => 'At capacity'], 503, ['Retry-After' => '7'])
        ->push(['status' => 'success', 'audio_base64' => base64_encode('RIFF')]);

    $audio = app(AbenaTextToSpeech::class)->synthesize('Akwaaba', 'abena_twi_high');

    expect($audio)->toBe('RIFF');
    Sleep::assertSequence([Sleep::for(7)->seconds()]);
});
