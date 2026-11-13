<?php

namespace App\Services\Conversation;

/**
 * What the assistant says back, as an ordered list of segments. A segment
 * is either a recorded clip (or its wording as a fallback) or dynamic
 * English speech such as an amount or date.
 */
final readonly class AssistantReply
{
    public const string Ended = 'ended';

    /**
     * @param  list<array{type: string, key?: string, text: string, audio_url?: string|null, language: string}>  $segments
     * @param  string  $state  The step the call now waits on, or "ended"
     * @param  string|null  $intent  What the caller's answer was taken to mean, e.g. the branch picked
     * @param  string|null  $stepType  The type of step the call waits on, e.g. "ask_number"
     */
    public function __construct(
        public array $segments,
        public string $state,
        public ?string $heard = null,
        public ?string $intent = null,
        public bool $suggestKeypad = false,
        public ?string $stepType = null,
    ) {}

    public function hasEnded(): bool
    {
        return $this->state === self::Ended;
    }

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
     * @return array{state: string, step_type: string|null, ended: bool, heard: string|null, intent: string|null, suggest_keypad: bool, segments: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'step_type' => $this->stepType,
            'ended' => $this->hasEnded(),
            'heard' => $this->heard,
            'intent' => $this->intent,
            'suggest_keypad' => $this->suggestKeypad,
            'segments' => $this->segments,
        ];
    }
}
