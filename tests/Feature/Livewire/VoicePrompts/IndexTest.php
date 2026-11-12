<?php

use App\Livewire\VoicePrompts\Index;
use App\Models\User;
use App\Models\VoicePrompt;
use App\Services\Audio\AudioTranscoder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('saves new wording and stores the recording converted for browser and IVR', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create());
    $prompt = VoicePrompt::factory()->create(['key' => 'greeting', 'text' => 'Old']);
    $transcoder = $this->mock(AudioTranscoder::class);
    $transcoder->shouldReceive('forBrowserPlayback')->once()->andReturn('mp3-bytes');
    $transcoder->shouldReceive('forTelephony')->once()->andReturn('wav-8k-bytes');

    Livewire::test(Index::class)
        ->call('edit', $prompt->id)
        ->set('text', 'Akwaaba,')
        ->set('recording', UploadedFile::fake()->createWithContent('greeting.wav', 'RIFF')->mimeType('audio/wav'))
        ->call('save')
        ->assertHasNoErrors();

    $prompt->refresh();
    expect($prompt->text)->toBe('Akwaaba,');
    Storage::disk('public')->assertExists($prompt->audio_path);
    Storage::disk('public')->assertExists($prompt->telephony_audio_path);
    expect(Storage::disk('public')->get($prompt->telephony_audio_path))->toBe('wav-8k-bytes');
});

test('generates prompt audio with the configured Abena voice', function () {
    Storage::fake('public');
    config(['services.abena.url' => 'https://abena.test/api/v1', 'services.abena.voices.twi' => 'abena_twi_high']);
    Http::preventStrayRequests();
    Http::fake(['https://abena.test/api/v1/tts/synthesize/' => Http::response(['status' => 'success', 'audio_base64' => base64_encode('RIFF')])]);
    $this->actingAs(User::factory()->create());
    $prompt = VoicePrompt::factory()->create(['key' => 'greeting', 'text' => 'Akwaaba,']);
    $transcoder = $this->mock(AudioTranscoder::class);
    $transcoder->shouldReceive('forBrowserPlayback')->once()->andReturn('mp3-bytes');
    $transcoder->shouldReceive('forTelephony')->once()->andReturn('wav-8k-bytes');

    Livewire::test(Index::class)->call('generate', $prompt->id);

    Http::assertSent(fn ($request): bool => $request['voice'] === 'abena_twi_high' && $request['text'] === 'Akwaaba,');
    Storage::disk('public')->assertExists($prompt->fresh()->audio_path);
});
