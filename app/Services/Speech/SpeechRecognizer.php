<?php

namespace App\Services\Speech;

use App\Enums\Language;
use App\Models\CallSession;

interface SpeechRecognizer
{
    /**
     * Transcribe a recorded caller utterance.
     *
     * @throws SpeechRecognitionFailed
     * @throws SpeechQuotaExceeded
     */
    public function transcribe(string $audio, string $mimeType, Language $language, ?CallSession $callSession = null): string;
}
