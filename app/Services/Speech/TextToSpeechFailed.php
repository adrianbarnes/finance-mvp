<?php

namespace App\Services\Speech;

use RuntimeException;

class TextToSpeechFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }

    /**
     * Credits used up or rate limited: further requests will fail too.
     */
    public function isAllowanceExhausted(): bool
    {
        return in_array($this->httpStatus, [402, 429], true);
    }
}
