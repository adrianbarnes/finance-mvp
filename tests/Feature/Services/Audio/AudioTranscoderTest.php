<?php

use App\Services\Audio\AudioTranscoder;
use App\Services\Audio\AudioTranscodingFailed;
use Illuminate\Support\Facades\Process;

/**
 * Generate test audio with ffmpeg's built-in sources.
 */
function generateAudio(string $filter): string
{
    $path = tempnam(sys_get_temp_dir(), 'audio-test-').'.wav';
    Process::run([config('services.ffmpeg.binary'), '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', $filter, $path])->throw();

    return tap((string) file_get_contents($path), fn () => @unlink($path));
}

beforeEach(function () {
    if (! Process::run([config('services.ffmpeg.binary'), '-version'])->successful()) {
        $this->markTestSkipped('ffmpeg is not installed.');
    }
});

test('converts caller audio to 16 kHz mono WAV and keeps the speech', function () {
    $audio = generateAudio('sine=frequency=300:sample_rate=48000:duration=1');

    $prepared = app(AudioTranscoder::class)->prepareForRecognition($audio);

    expect(substr($prepared->contents, 0, 4))->toBe('RIFF')
        ->and(unpack('v', substr($prepared->contents, 22, 2))[1])->toBe(1)
        ->and(unpack('V', substr($prepared->contents, 24, 4))[1])->toBe(16000)
        ->and($prepared->hasSpeech())->toBeTrue();
});

test('reports no speech for silent audio', function () {
    $audio = generateAudio('anullsrc=r=48000:cl=mono:d=2');

    expect(app(AudioTranscoder::class)->prepareForRecognition($audio)->hasSpeech())->toBeFalse();
});

test('produces 8 kHz telephony WAV for IVR playback', function () {
    $audio = generateAudio('sine=frequency=440:sample_rate=44100:duration=0.5');

    $telephony = app(AudioTranscoder::class)->forTelephony($audio);

    expect(unpack('V', substr($telephony, 24, 4))[1])->toBe(8000);
});

test('throws when the input is not audio', function () {
    expect(fn () => app(AudioTranscoder::class)->prepareForRecognition('not audio at all'))->toThrow(AudioTranscodingFailed::class);
});
