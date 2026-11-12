<?php

namespace App\Services\Conversation;

use App\Enums\CallState;
use App\Enums\Intent;

/**
 * What the assistant says back, as an ordered list of segments. A segment
 * is either a voice prompt (recorded audio, or its text as a fallback) or
 * dynamic English speech such as an amount or date.
 */
final readonly class AssistantReply
{
    /**
     * @param  list<array{type: string, key?: string, text: string, audio_url?: string|null, language: string}>  $segments
     */
    public function __construct(
        public array $segments,
        public CallState $state,
        public ?string $heard = null,
        public ?Intent $intent = null,
        public bool $suggestKeypad = false,
    ) {}

    public function text(): string
    {
        return implode(' ', array_column($this->segments, 'text'));
    }

    /**
     * @return list<string>
     */
    public function promptKeys(): array
    {
        return array_values(array_filter(array_map(fn (array $segment): ?string => $segment['key'] ?? null, $this->segments)));
    }

    /**
     * @return array{state: string, ended: bool, heard: string|null, intent: string|null, suggest_keypad: bool, segments: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'ended' => $this->state === CallState::Ended,
            'heard' => $this->heard,
            'intent' => $this->intent?->value,
            'suggest_keypad' => $this->suggestKeypad,
            'segments' => $this->segments,
        ];
    }
}
