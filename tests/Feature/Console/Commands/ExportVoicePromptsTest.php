<?php

use App\Enums\Language;
use App\Models\VoicePrompt;
use Database\Seeders\VoicePromptSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->bundlePath = sys_get_temp_dir().'/voice-prompt-bundle-'.uniqid();
    config(['conversation.voice_prompt_bundle_path' => $this->bundlePath]);
});

afterEach(function () {
    File::deleteDirectory($this->bundlePath);
});

test('exports prompt audio into a folder per function, step and language', function () {
    Storage::fake('public');
    Storage::disk('public')->put('voice-prompts/twi/greeting-20261112.mp3', 'mp3-bytes');
    Storage::disk('public')->put('voice-prompts/twi/greeting-20261112-8k.wav', 'wav-bytes');
    VoicePrompt::factory()->create([
        'key' => 'greeting', 'language' => Language::Twi, 'text' => 'Akwaaba,',
        'audio_path' => 'voice-prompts/twi/greeting-20261112.mp3', 'telephony_audio_path' => 'voice-prompts/twi/greeting-20261112-8k.wav',
    ]);
    VoicePrompt::factory()->create(['key' => 'greeting', 'language' => Language::Ewe, 'text' => 'Woezɔ,']);

    $this->artisan('voice-prompts:export')->assertSuccessful();

    expect(json_decode(File::get($this->bundlePath.'/manifest.json'), true))->toBe([
        'ewe' => ['greeting' => ['text' => 'Woezɔ,', 'audio' => null, 'telephony_audio' => null]],
        'twi' => ['greeting' => ['text' => 'Akwaaba,', 'audio' => 'call/greeting/twi/greeting.mp3', 'telephony_audio' => 'call/greeting/twi/greeting-8k.wav']],
    ])->and(File::get($this->bundlePath.'/call/greeting/twi/greeting.mp3'))->toBe('mp3-bytes');
});

test('seeding a fresh install recreates prompts with their bundled audio', function () {
    File::ensureDirectoryExists($this->bundlePath.'/call/greeting/twi');
    File::put($this->bundlePath.'/call/greeting/twi/greeting.mp3', 'mp3-bytes');
    File::put($this->bundlePath.'/call/greeting/twi/greeting-8k.wav', 'wav-bytes');
    File::put($this->bundlePath.'/manifest.json', json_encode([
        'twi' => ['greeting' => ['text' => 'Akwaaba,', 'audio' => 'call/greeting/twi/greeting.mp3', 'telephony_audio' => 'call/greeting/twi/greeting-8k.wav']],
    ]));
    Storage::fake('public');

    $this->seed(VoicePromptSeeder::class);

    $prompt = VoicePrompt::query()->where('key', 'greeting')->sole();
    expect($prompt->text)->toBe('Akwaaba,')
        ->and($prompt->audio_path)->toBe('voice-prompts/call/greeting/twi/greeting.mp3')
        ->and(Storage::disk('public')->get($prompt->audio_path))->toBe('mp3-bytes')
        ->and(Storage::disk('public')->get($prompt->telephony_audio_path))->toBe('wav-bytes');
});

test('seeding keeps wording that was edited locally', function () {
    File::ensureDirectoryExists($this->bundlePath);
    File::put($this->bundlePath.'/manifest.json', json_encode([
        'twi' => ['greeting' => ['text' => 'Akwaaba,', 'audio' => null, 'telephony_audio' => null]],
    ]));
    VoicePrompt::factory()->create(['key' => 'greeting', 'language' => Language::Twi, 'text' => 'Edited locally']);

    $this->seed(VoicePromptSeeder::class);

    expect(VoicePrompt::query()->where('key', 'greeting')->value('text'))->toBe('Edited locally');
});
