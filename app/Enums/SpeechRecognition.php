<?php

namespace App\Enums;

/**
 * How a caller's speech is understood in a language.
 */
enum SpeechRecognition: string
{
    /** Sent to the Khaya ASR API (Twi, Ewe and other Ghanaian languages). */
    case Khaya = 'khaya';

    /** Recognised by the browser phone itself (English, for testing). */
    case Browser = 'browser';

    /** No speech model yet: callers answer with the keypad only. */
    case Keypad = 'keypad';

    public function label(): string
    {
        return match ($this) {
            self::Khaya => 'Khaya ASR',
            self::Browser => 'Browser (testing)',
            self::Keypad => 'Keypad only',
        };
    }
}
