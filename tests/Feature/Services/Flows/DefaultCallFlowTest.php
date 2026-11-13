<?php

use App\Enums\FlowStatus;
use App\Services\Flows\DefaultCallFlow;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->bundlePath = storage_path('framework/testing/flow-bundle-'.uniqid());
    config(['conversation.default_flow_bundle_path' => $this->bundlePath]);
});

afterEach(function () {
    File::deleteDirectory($this->bundlePath);
});

test('builds a draft whose clips carry the bundle\'s wording and audio', function () {
    File::ensureDirectoryExists("{$this->bundlePath}/call/greeting/twi");
    File::put("{$this->bundlePath}/call/greeting/twi/greeting.mp3", 'greeting-mp3');
    File::put("{$this->bundlePath}/call/greeting/twi/greeting-8k.wav", str_repeat('w', 44 + 3_200));
    File::put("{$this->bundlePath}/manifest.json", json_encode([
        'twi' => ['greeting' => ['text' => 'Akwaaba', 'audio' => 'call/greeting/twi/greeting.mp3', 'telephony_audio' => 'call/greeting/twi/greeting-8k.wav']],
        'ewe' => ['greeting' => ['text' => 'Woezɔ', 'audio' => null, 'telephony_audio' => null]],
    ]));

    $flow = app(DefaultCallFlow::class)->create();

    $clip = $flow->clips()->where('key', 'greeting')->sole();
    $twi = $clip->recordings()->where('language_id', language('twi')->id)->sole();
    $ewe = $clip->recordings()->where('language_id', language('ewe')->id)->sole();

    expect($flow->status)->toBe(FlowStatus::Draft)
        ->and($clip->group)->toBe('call')
        ->and($twi->text)->toBe('Akwaaba')
        ->and($twi->audio_path)->toBe('flow-audio/'.sha1('greeting-mp3').'.mp3')
        ->and($twi->duration_ms)->toBe(200)
        ->and($ewe->text)->toBe('Woezɔ')
        ->and($ewe->audio_path)->toBeNull();
    Storage::disk('public')->assertExists($twi->audio_path);
});

test('gives menu options the keywords of each language', function () {
    $flow = app(DefaultCallFlow::class)->create();

    $balance = $flow->steps()->where('name', 'Balance')->where('type', 'branch')->sole()->load('keywords');

    expect($balance->keypad_digit)->toBe('1')
        ->and($balance->keywordsFor(language('twi')))->toContain('sika dodoo')
        ->and($balance->keywordsFor(language('eng')))->toContain('balance');
});
