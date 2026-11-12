<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Conversation settings that testers switch on and off from the UI.
 */
class ConversationSettings
{
    private const string RememberLanguage = 'conversation.remember_language';

    /**
     * Whether customers' language choices are saved and reused: returning
     * customers then skip the language prompt. When off, every call starts
     * with the language prompt and the choice is used for that call only.
     */
    public function remembersLanguage(): bool
    {
        return (bool) AppSetting::valueOf(self::RememberLanguage, true);
    }

    public function setRemembersLanguage(bool $remember): void
    {
        AppSetting::put(self::RememberLanguage, $remember);
    }
}
