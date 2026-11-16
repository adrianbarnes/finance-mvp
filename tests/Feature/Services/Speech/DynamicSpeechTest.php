<?php

use App\Services\Audio\AudioTranscoder;
use App\Services\Speech\DynamicSpeech;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['services.abena.url' => 'https://abena.test/api/v1', 'services.abena.english_voice' => 'akua_eng']);
});

test('voices a phrase once and reuses the cached audio afterwards', function () {
    config(['services.abena.dynamic_speech' => true]);
    Storage::fake('public');
    Http::preventStrayRequests();
    Http::fake(['https://abena.test/api/v1/tts/synthesize/' => Http::response(['status' => 'success', 'audio_base64' => base64_encode('RIFF')])]);
    $transcoder = $this->mock(AudioTranscoder::class);
    $transcoder->shouldReceive('forBrowserPlayback')->andReturn('mp3');
    $transcoder->shouldReceive('forTelephony')->andReturn('wav');

    $first = app(DynamicSpeech::class)->segment('1,250 cedis');
    $second = app(DynamicSpeech::class)->segment('1,250 cedis');

    expect($first['audio_url'])->not->toBeNull()
        ->and($second['audio_url'])->toBe($first['audio_url'])
        ->and($first['telephony_audio_url'])->toEndWith('-8k.wav');
    Http::assertSentCount(1);
});

test('leaves the text for the client to read when synthesis fails', function () {
    config(['services.abena.dynamic_speech' => true]);
    Storage::fake('public');
    Http::preventStrayRequests();
    Http::fake(['https://abena.test/api/v1/tts/synthesize/' => Http::response(['message' => 'At capacity'], 503)]);

    expect(app(DynamicSpeech::class)->segment('1,250 cedis'))
        ->toBe(['type' => 'speech', 'text' => '1,250 cedis', 'language' => 'en', 'audio_url' => null, 'telephony_audio_url' => null]);
});

test('does not call Abena when dynamic speech is switched off', function () {
    config(['services.abena.dynamic_speech' => false]);
    Http::preventStrayRequests();

    expect(app(DynamicSpeech::class)->segment('1,250 cedis')['audio_url'])->toBeNull();

    Http::assertNothingSent();
});
