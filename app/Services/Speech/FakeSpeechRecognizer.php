<?php

namespace App\Services\Speech;

use App\Models\CallSession;
use App\Models\Language;
use Throwable;

/**
 * Offline recognizer for local development and tests. Returns queued
 * transcripts (or throws queued exceptions) in order, then empty text.
 */
class FakeSpeechRecognizer implements SpeechRecognizer
{
    /**
     * @var list<array{mime_type: string, language: string, bytes: int}>
     */
    public array $received = [];

    /**
     * @param  list<string|Throwable>  $responses
     */
    public function __construct(private array $responses = []) {}

    public function transcribe(string $audio, string $mimeType, Language $language, ?CallSession $callSession = null): string
    {
        $this->received[] = ['mime_type' => $mimeType, 'language' => $language->code, 'bytes' => strlen($audio)];

        $response = array_shift($this->responses) ?? '';

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}
