<?php

namespace App\Enums;

/**
 * Languages supported by the voice assistant. Values are ISO 639-3 codes,
 * as expected by the Khaya ASR v3 API. English is for testing and is
 * recognised in the browser rather than by Khaya.
 */
enum Language: string
{
    case Twi = 'twi';
    case Fante = 'fat';
    case Ga = 'gaa';
    case Ewe = 'ewe';
    case English = 'eng';

    public function label(): string
    {
        return match ($this) {
            self::Twi => 'Twi',
            self::Fante => 'Fante',
            self::Ga => 'Ga',
            self::Ewe => 'Ewe',
            self::English => 'English',
        };
    }

    /**
     * Languages switched on for calls via config('conversation.enabled_languages').
     *
     * @return list<self>
     */
    public static function enabled(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $language): bool => in_array($language->value, config('conversation.enabled_languages'), true),
        ));
    }

    /**
     * Whether speech in this language is recognised on the client (the
     * browser phone) instead of by the Khaya API.
     */
    public function isRecognizedByClient(): bool
    {
        return in_array($this->value, config('conversation.client_recognized_languages'), true);
    }

    /**
     * Keypad number that picks this language on the language prompt: its
     * position in the enabled languages, starting at 1.
     */
    public function keypadNumber(): ?string
    {
        $position = array_search($this, self::enabled(), true);

        return $position === false ? null : (string) ($position + 1);
    }
}
