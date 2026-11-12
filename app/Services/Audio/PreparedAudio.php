<?php

namespace App\Services\Audio;

/**
 * Caller audio converted for speech recognition: 16 kHz mono 16-bit WAV
 * with leading, trailing and long internal silences removed.
 */
final readonly class PreparedAudio
{
    public const string MimeType = 'audio/wav';

    public function __construct(
        public string $contents,
        public float $speechSeconds,
    ) {}

    /**
     * Whether enough speech is left after trimming silence to be worth
     * sending to the speech recognizer.
     */
    public function hasSpeech(): bool
    {
        return $this->speechSeconds >= (float) config('services.ffmpeg.min_speech_seconds');
    }
}
