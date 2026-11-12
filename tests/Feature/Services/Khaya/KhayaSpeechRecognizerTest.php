<?php

use App\Enums\Language;
use App\Models\KhayaApiCall;
use App\Services\Khaya\KhayaSpeechRecognizer;
use App\Services\Speech\SpeechQuotaExceeded;
use App\Services\Speech\SpeechRecognitionFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.khaya.key' => 'test-key',
        'services.khaya.asr_url' => 'https://khaya.test/asr/v3',
        'services.khaya.monthly_quota' => 100,
        'services.khaya.per_minute_limit' => 10,
    ]);
});

test('sends raw audio to the transcribe endpoint and logs the call', function () {
    Http::preventStrayRequests();
    Http::fake(['https://khaya.test/asr/v3/transcribe*' => Http::response(['text' => ' me pɛ bosea '])]);

    $transcript = app(KhayaSpeechRecognizer::class)->transcribe('wav-bytes', 'audio/x-wav', Language::Twi);

    expect($transcript)->toBe('me pɛ bosea');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://khaya.test/asr/v3/transcribe?language=twi'
        && $request->hasHeader('Ocp-Apim-Subscription-Key', 'test-key')
        && $request->hasHeader('Content-Type', 'audio/wav')
        && $request->body() === 'wav-bytes');
    $this->assertDatabaseHas('khaya_api_calls', ['endpoint' => 'asr.transcribe', 'language' => 'twi', 'http_status' => 200, 'error_code' => null]);
});

test('logs and reports the Khaya error code when transcription fails', function () {
    Http::preventStrayRequests();
    Http::fake(['https://khaya.test/asr/v3/transcribe*' => Http::response([
        'error' => ['code' => 'VALIDATION_FAILED', 'message' => 'Invalid', 'details' => [['code' => 'INVALID_AUDIO_FORMAT', 'target' => 'audio', 'message' => 'Unsupported']]],
    ], 400)]);

    expect(fn () => app(KhayaSpeechRecognizer::class)->transcribe('bytes', 'audio/wav', Language::Twi))
        ->toThrow(fn (SpeechRecognitionFailed $exception) => expect($exception->errorCode)->toBe('INVALID_AUDIO_FORMAT'));

    $this->assertDatabaseHas('khaya_api_calls', ['http_status' => 400, 'error_code' => 'INVALID_AUDIO_FORMAT']);
});

test('refuses to call Khaya once the monthly quota is used', function () {
    Http::preventStrayRequests();
    $this->travelTo('2026-11-20 10:00:00');
    config(['services.khaya.monthly_quota' => 3]);
    KhayaApiCall::factory()->count(3)->create(['created_at' => '2026-11-02 08:00:00']);

    expect(fn () => app(KhayaSpeechRecognizer::class)->transcribe('bytes', 'audio/wav', Language::Twi))
        ->toThrow(fn (SpeechQuotaExceeded $exception) => expect($exception->window)->toBe(SpeechQuotaExceeded::Monthly));

    Http::assertNothingSent();
});

test('does not count last month against the monthly quota', function () {
    Http::preventStrayRequests();
    Http::fake(['https://khaya.test/asr/v3/transcribe*' => Http::response(['text' => 'aane'])]);
    $this->travelTo('2026-11-01 00:30:00');
    config(['services.khaya.monthly_quota' => 3]);
    KhayaApiCall::factory()->count(3)->create(['created_at' => '2026-10-31 23:00:00']);

    expect(app(KhayaSpeechRecognizer::class)->transcribe('bytes', 'audio/wav', Language::Twi))->toBe('aane');
});

test('refuses to call Khaya when the per-minute limit is reached', function () {
    Http::preventStrayRequests();
    $this->travelTo('2026-11-20 10:00:30');
    KhayaApiCall::factory()->count(10)->create(['created_at' => '2026-11-20 10:00:00']);

    expect(fn () => app(KhayaSpeechRecognizer::class)->transcribe('bytes', 'audio/wav', Language::Twi))
        ->toThrow(fn (SpeechQuotaExceeded $exception) => expect($exception->window)->toBe(SpeechQuotaExceeded::PerMinute));

    Http::assertNothingSent();
});

test('fails without calling Khaya when no API key is configured', function () {
    Http::preventStrayRequests();
    config(['services.khaya.key' => null]);

    expect(fn () => app(KhayaSpeechRecognizer::class)->transcribe('bytes', 'audio/wav', Language::Twi))
        ->toThrow(fn (SpeechRecognitionFailed $exception) => expect($exception->errorCode)->toBe('MISSING_API_KEY'));

    Http::assertNothingSent();
});

test('refuses English, which the browser recognises, without using quota', function () {
    Http::preventStrayRequests();

    expect(fn () => app(KhayaSpeechRecognizer::class)->transcribe('bytes', 'audio/wav', Language::English))
        ->toThrow(fn (SpeechRecognitionFailed $exception) => expect($exception->errorCode)->toBe('UNSUPPORTED_LANGUAGE'));

    Http::assertNothingSent();
    $this->assertDatabaseCount('khaya_api_calls', 0);
});
