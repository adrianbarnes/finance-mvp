<?php

namespace App\Enums;

/**
 * The moments a step speaks: when it starts, when the answer was not
 * understood, and (for numbers) when the answer is outside the range.
 */
enum PromptSlot: string
{
    case Prompt = 'prompt';
    case Reprompt = 'reprompt';
    case OutOfRange = 'out_of_range';

    public function label(): string
    {
        return match ($this) {
            self::Prompt => 'Audio',
            self::Reprompt => 'When not understood',
            self::OutOfRange => 'When outside the range',
        };
    }

    /**
     * Slots a step of this type can fill.
     *
     * @return list<self>
     */
    public static function forType(StepType $type): array
    {
        return match (true) {
            $type === StepType::AskNumber => [self::Prompt, self::Reprompt, self::OutOfRange],
            $type->asksQuestion() => [self::Prompt, self::Reprompt],
            $type->speaks(), $type === StepType::Branch => [self::Prompt],
            default => [],
        };
    }
}
