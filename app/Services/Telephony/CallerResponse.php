<?php

namespace App\Services\Telephony;

use App\Services\Conversation\CallInput;

/**
 * What a caller did in one turn, in provider-neutral terms: keys, speech
 * the provider already recognised, audio to send to speech recognition,
 * silence, or hanging up.
 */
final readonly class CallerResponse
{
    private function __construct(
        public ?CallInput $input = null,
        public ?string $audio = null,
        public string $audioMimeType = 'audio/wav',
        public bool $hungUp = false,
    ) {}

    /**
     * Keys, recognised speech or no input, for the engine to answer.
     */
    public static function input(CallInput $input): self
    {
        return new self(input: $input);
    }

    /**
     * A recording of the caller, for speech recognition (Khaya).
     */
    public static function audio(string $audio, string $mimeType = 'audio/wav'): self
    {
        return new self(audio: $audio, audioMimeType: $mimeType);
    }

    public static function hungUp(): self
    {
        return new self(hungUp: true);
    }
}
