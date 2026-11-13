<?php

namespace App\Enums;

/**
 * The kinds of step an IVR flow is built from. Branches are the paths
 * under a choice or a check; every other type can be added anywhere.
 */
enum StepType: string
{
    case Message = 'message';
    case Choice = 'choice';
    case Check = 'check';
    case AskNumber = 'ask_number';
    case AskSpeech = 'ask_speech';
    case ChooseLanguage = 'choose_language';
    case Do = 'do';
    case GoTo = 'go_to';
    case Transfer = 'transfer';
    case EndCall = 'end_call';
    case Branch = 'branch';

    public function label(): string
    {
        return match ($this) {
            self::Message => 'Play message',
            self::Choice => 'Choice / menu',
            self::Check => 'Check',
            self::AskNumber => 'Ask for a number',
            self::AskSpeech => 'Ask for speech',
            self::ChooseLanguage => 'Choose language',
            self::Do => 'Do',
            self::GoTo => 'Go to',
            self::Transfer => 'Transfer to agent',
            self::EndCall => 'End call',
            self::Branch => 'Branch',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Message => 'Plays audio, then moves on.',
            self::Choice => 'Asks a question; each branch is picked by its key or words.',
            self::Check => 'Branches on what is known about the caller.',
            self::AskNumber => 'Asks for an amount or number and saves the answer.',
            self::AskSpeech => 'Asks for a spoken answer, e.g. a name, and saves it.',
            self::ChooseLanguage => 'Offers each language in its own language.',
            self::Do => 'Registers the caller or submits a loan application.',
            self::GoTo => 'Jumps to another step.',
            self::Transfer => 'Hands the call to an agent (stage 2).',
            self::EndCall => 'Ends the call.',
            self::Branch => 'A path under a choice or check.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Message => 'speaker-wave',
            self::Choice => 'list-bullet',
            self::Check => 'adjustments-horizontal',
            self::AskNumber => 'hashtag',
            self::AskSpeech => 'microphone',
            self::ChooseLanguage => 'language',
            self::Do => 'bolt',
            self::GoTo => 'arrow-uturn-left',
            self::Transfer => 'phone-arrow-up-right',
            self::EndCall => 'phone-x-mark',
            self::Branch => 'arrow-turn-down-right',
        };
    }

    /**
     * Whether the step speaks to the caller, so it needs audio.
     */
    public function speaks(): bool
    {
        return in_array($this, [self::Message, self::Choice, self::AskNumber, self::AskSpeech, self::ChooseLanguage, self::Transfer], true);
    }

    /**
     * Whether the step waits for an answer, so it can have a reprompt
     * played when the answer is not understood.
     */
    public function asksQuestion(): bool
    {
        return in_array($this, [self::Choice, self::AskNumber, self::AskSpeech], true);
    }

    public function hasBranches(): bool
    {
        return in_array($this, [self::Choice, self::Check], true);
    }

    /**
     * Step types offered when adding a step to the flow.
     *
     * @return list<self>
     */
    public static function addable(): array
    {
        return [
            self::Message,
            self::Choice,
            self::Check,
            self::AskNumber,
            self::AskSpeech,
            self::ChooseLanguage,
            self::Do,
            self::GoTo,
            self::Transfer,
            self::EndCall,
        ];
    }
}
