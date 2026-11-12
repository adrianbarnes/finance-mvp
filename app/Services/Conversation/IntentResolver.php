<?php

namespace App\Services\Conversation;

use App\Enums\Intent;
use App\Enums\Language;

/**
 * Keyword-based intent matching on the transcript in the caller's own
 * language, so no translation call is spent per turn.
 */
class IntentResolver
{
    /**
     * Tie-break order when an utterance matches several intents. Status and
     * repayment questions usually also mention "loan", so they come first.
     *
     * @var list<Intent>
     */
    private const array MenuPriority = [
        Intent::SpeakToAgent,
        Intent::ApplicationStatus,
        Intent::RepaymentDue,
        Intent::ApplyLoan,
        Intent::CheckBalance,
        Intent::Goodbye,
        Intent::Repeat,
        Intent::Yes,
        Intent::No,
    ];

    /**
     * @var list<Intent>
     */
    private const array ConfirmationPriority = [
        Intent::Yes,
        Intent::No,
        Intent::Goodbye,
        Intent::Repeat,
        Intent::SpeakToAgent,
        Intent::ApplicationStatus,
        Intent::RepaymentDue,
        Intent::ApplyLoan,
        Intent::CheckBalance,
    ];

    public function resolve(string $transcript, Language $language, bool $expectsConfirmation = false): Intent
    {
        $normalized = TranscriptNormalizer::normalize($transcript);

        if ($normalized === '') {
            return Intent::Unknown;
        }

        $lexicon = config("conversation.lexicon.{$language->value}", []);
        $bestIntent = Intent::Unknown;
        $bestScore = 0;

        foreach ($expectsConfirmation ? self::ConfirmationPriority : self::MenuPriority as $intent) {
            $score = $this->score($normalized, $lexicon[$intent->value] ?? []);

            if ($score > $bestScore) {
                $bestIntent = $intent;
                $bestScore = $score;
            }
        }

        return $bestIntent;
    }

    /**
     * Find which enabled language the caller accepted on the single
     * language-choice prompt, from their "yes" word or the language name.
     */
    public function chosenLanguage(string $transcript): ?Language
    {
        $normalized = TranscriptNormalizer::normalize($transcript);

        if ($normalized === '') {
            return null;
        }

        foreach (Language::enabled() as $language) {
            if ($this->score($normalized, config("conversation.language_affirmatives.{$language->value}", [])) > 0) {
                return $language;
            }
        }

        return null;
    }

    /**
     * Find the enabled language whose keypad number was pressed on the
     * language-choice prompt.
     */
    public function chosenLanguageByKeypad(string $digits): ?Language
    {
        foreach (Language::enabled() as $language) {
            if ($language->keypadNumber() === $digits) {
                return $language;
            }
        }

        return null;
    }

    /**
     * Resolve a keypad press using the DTMF map for the current prompt.
     */
    public function resolveKeypad(string $digits, bool $expectsConfirmation = false): Intent
    {
        $map = config($expectsConfirmation ? 'conversation.keypad.confirmation' : 'conversation.keypad.main_menu');

        return Intent::tryFrom($map[$digits] ?? '') ?? Intent::Unknown;
    }

    /**
     * @param  list<string>  $phrases
     */
    private function score(string $normalized, array $phrases): int
    {
        $score = 0;

        foreach ($phrases as $phrase) {
            if (str_starts_with($phrase, '=')) {
                $score += $normalized === TranscriptNormalizer::normalize(substr($phrase, 1)) ? 1 : 0;

                continue;
            }

            $pattern = '/(?:^|\s)'.preg_quote(TranscriptNormalizer::normalize($phrase), '/').'(?:$|\s)/u';
            $score += preg_match($pattern, $normalized);
        }

        return $score;
    }
}
