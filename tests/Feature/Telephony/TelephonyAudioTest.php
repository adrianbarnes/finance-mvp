<?php

use Illuminate\Support\Facades\Storage;

test('serves prompt audio with an audio content type that providers may cache', function (string $path, string $contentType) {
    Storage::disk('public')->put($path, 'RIFF-audio');

    $response = $this->get(route('telephony.audio', ['path' => $path]));

    $response->assertOk()
        ->assertHeader('Content-Type', $contentType)
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    expect($response->streamedContent())->toBe('RIFF-audio');
})->with([
    'telephony wav' => ['flow-audio/abc.wav', 'audio/wav'],
    'spoken value' => ['speech-cache/akua_eng/abc.mp3', 'audio/mpeg'],
]);

test('serves nothing outside the prompt audio folders', function (string $path) {
    Storage::disk('public')->put('flow-audio/abc.wav', 'RIFF-audio');
    Storage::disk('public')->put('other/secret.wav', 'private');

    $this->get('/telephony/audio/'.$path)->assertNotFound();
})->with([
    'another folder' => ['other/secret.wav'],
    'path traversal' => ['flow-audio/../other/secret.wav'],
    'not audio' => ['flow-audio/abc.txt'],
    'missing file' => ['flow-audio/missing.wav'],
]);
