<?php

namespace App\Services\Conversation;

use App\Enums\InputMode;

/**
 * What the caller said or pressed in one turn.
 */
final readonly class CallInput
{
    public function __construct(
        public InputMode $mode,
        public string $value,
        public ?string $audioPath = null,
        public ?int $recognitionMs = null,
    ) {}

    public static function speech(string $transcript, ?string $audioPath = null, ?int $recognitionMs = null): self
    {
        return new self(InputMode::Speech, $transcript, $audioPath, $recognitionMs);
    }

    public static function keypad(string $digits): self
    {
        return new self(InputMode::Keypad, $digits);
    }

    /**
     * The caller said nothing (or only noise) before the timeout.
     */
    public static function noInput(?string $audioPath = null): self
    {
        return new self(InputMode::NoInput, '', $audioPath);
    }

    public static function text(string $text): self
    {
        return new self(InputMode::Text, $text);
    }
}
