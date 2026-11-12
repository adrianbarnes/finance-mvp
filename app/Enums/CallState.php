<?php

namespace App\Enums;

/**
 * Where a caller is in the voice dialogue.
 */
enum CallState: string
{
    case ChooseLanguage = 'choose_language';
    case OfferRegistration = 'offer_registration';
    case AskName = 'ask_name';
    case ConfirmName = 'confirm_name';
    case MainMenu = 'main_menu';
    case ApplyAmount = 'apply_amount';
    case ApplyTerm = 'apply_term';
    case ApplyConfirm = 'apply_confirm';
    case AnythingElse = 'anything_else';
    case Ended = 'ended';

    /**
     * Whether the state asks a yes / no question.
     */
    public function expectsConfirmation(): bool
    {
        return in_array($this, [self::OfferRegistration, self::ConfirmName, self::ApplyConfirm, self::AnythingElse], true);
    }

    /**
     * The prompt that asks this state's question again.
     */
    public function questionPromptKey(): string
    {
        return match ($this) {
            self::ChooseLanguage => 'choose_language',
            self::OfferRegistration => 'offer_registration',
            self::AskName => 'ask_name',
            self::ConfirmName => 'confirm_name_question',
            self::ApplyAmount => 'ask_amount',
            self::ApplyTerm => 'ask_term',
            self::ApplyConfirm => 'confirm_question',
            self::AnythingElse => 'anything_else',
            self::MainMenu, self::Ended => 'main_menu',
        };
    }
}
