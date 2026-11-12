<?php

namespace App\Services\Speech;

use RuntimeException;

class SpeechQuotaExceeded extends RuntimeException
{
    public const string Monthly = 'monthly';

    public const string PerMinute = 'per_minute';

    public function __construct(public readonly string $window)
    {
        parent::__construct($window === self::Monthly
            ? 'The monthly speech recognition quota has been used up.'
            : 'Too many speech recognition requests this minute.');
    }
}
