<?php

use App\Models\VoicePrompt;
use App\Services\Audio\AudioTranscoder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config([
        'services.abena.url' => 'https://abena.test/api/v1',
        'services.abena.voices.twi' => 'abena_twi_high',
    ]);
});

test('voices prompts without audio and pauses between requests', function () {
    Storage::fake('public');
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake(['https://abena.test/api/v1/tts/synthesize/' => Http::response(['status' => 'success', 'audio_base64' => base64_encode('RIFF')])]);
    $transcoder = $this->mock(AudioTranscoder::class);
    $transcoder->shouldReceive('forBrowserPlayback')->andReturn('mp3');
    $transcoder->shouldReceive('forTelephony')->andReturn('wav');
    $greeting = VoicePrompt::factory()->create(['key' => 'greeting']);
    $goodbye = VoicePrompt::factory()->create(['key' => 'goodbye']);
    $recorded = VoicePrompt::factory()->create(['key' => 'main_menu', 'audio_path' => 'voice-prompts/twi/main_menu.mp3']);

    $this->artisan('voice-prompts:synthesize')->assertSuccessful();

    expect($greeting->fresh()->audio_path)->not->toBeNull()
        ->and($goodbye->fresh()->telephony_audio_path)->not->toBeNull()
        ->and($recorded->fresh()->audio_path)->toBe('voice-prompts/twi/main_menu.mp3');
    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
});

test('stops when the Abena allowance is used up', function (?string $apiKey, string $message) {
    config(['services.abena.key' => $apiKey]);
    Storage::fake('public');
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake(['https://abena.test/api/v1/tts/synthesize/' => Http::response(['message' => 'Credits used'], 402)]);
    VoicePrompt::factory()->count(3)->create();

    $this->artisan('voice-prompts:synthesize')
        ->expectsOutputToContain($message)
        ->assertFailed();

    Http::assertSentCount(1);
})->with([
    'free tier' => [null, 'Add ABENA_API_KEY to .env'],
    'with an API key' => ['sk_test', 'Add credits on the Abena dashboard'],
]);
