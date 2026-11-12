<?php

namespace App\Services\Speech;

use RuntimeException;

class SpeechRecognitionFailed extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "Speech recognition failed [{$errorCode}].");
    }
}
