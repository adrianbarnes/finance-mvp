<?php

namespace App\Services\Conversation;

use App\Enums\BranchMatch;
use App\Models\FlowStep;
use App\Models\Language;
use Illuminate\Support\Collection;

/**
 * Keyword matching on the transcript in the caller's own language, so no
 * translation call is spent per turn: which branch of a choice the caller
 * picked, and which language they accepted on the language choice.
 *
 * Words are matched against a normalised transcript (see
 * TranscriptNormalizer). A word starting with "=" only matches when it is
 * the whole answer, e.g. English "no", which is also the Twi "the".
 *
 * When no word matches exactly, a word that sounds like one of them is
 * accepted (speech recognition often misspells short Twi and Ewe answers),
 * but only when exactly one option sounds like the answer.
 */
class IntentResolver
{
    /**
     * The branch picked by speech. The branch whose matching words are the
     * most specific (longest in total) wins; a tie goes to the earlier one.
     *
     * @param  Collection<int, FlowStep>  $branches  In order, keywords loaded
     */
    public function matchBranch(Collection $branches, string $transcript, Language $language): ?FlowStep
    {
        $normalized = TranscriptNormalizer::normalize($transcript);

        if ($normalized === '') {
            return null;
        }

        $options = $branches->where('is_otherwise', false)->values();
        $wordsByOption = $options->map(fn (FlowStep $branch): array => match (BranchMatch::tryFrom((string) $branch->configValue('match'))) {
            BranchMatch::Yes => $language->yes_words,
            BranchMatch::No => $language->no_words,
            default => $branch->keywordsFor($language),
        })->all();

        $index = $this->bestOption($normalized, $wordsByOption);

        return $index === null ? null : $options->get($index);
    }

    /**
     * The branch whose key was pressed.
     *
     * @param  Collection<int, FlowStep>  $branches
     */
    public function branchByKeypad(Collection $branches, string $digits): ?FlowStep
    {
        $key = rtrim($digits, '#');

        return $key === '' ? null : $branches->where('is_otherwise', false)->firstWhere('keypad_digit', $key);
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

        $languages = Language::enabledInOrder()->values();

        foreach ($languages as $language) {
            if ($this->score($normalized, $language->selection_words) > 0) {
                return $language;
            }
        }

        $index = $this->onlySoundAlike($normalized, $languages->map(fn (Language $language): array => $language->selection_words)->all());

        return $index === null ? null : $languages->get($index);
    }

    /**
     * Find the enabled language whose keypad number was pressed on the
     * language-choice prompt.
     */
    public function chosenLanguageByKeypad(string $digits): ?Language
    {
        $digits = rtrim($digits, '#');

        if (! ctype_digit($digits) || (int) $digits < 1) {
            return null;
        }

        return Language::enabledInOrder()->get((int) $digits - 1);
    }

    /**
     * The option whose words match the answer best (a tie goes to the
     * earlier one), or else the only option that sounds like it.
     *
     * @param  array<int, list<string>>  $wordsByOption
     */
    private function bestOption(string $normalized, array $wordsByOption): ?int
    {
        $best = null;
        $bestScore = 0;

        foreach ($wordsByOption as $index => $words) {
            $score = $this->score($normalized, $words);

            if ($score > $bestScore) {
                $best = $index;
                $bestScore = $score;
            }
        }

        return $best ?? $this->onlySoundAlike($normalized, $wordsByOption);
    }

    /**
     * The one option with a word that sounds like the answer. Null when
     * none does, or when several do, so the caller is asked again rather
     * than guessed for.
     *
     * @param  array<int, list<string>>  $wordsByOption
     */
    private function onlySoundAlike(string $normalized, array $wordsByOption): ?int
    {
        $matches = array_keys(array_filter($wordsByOption, fn (array $words): bool => $this->soundsLike($normalized, $words)));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Whether part of the answer sounds like one of the words. A word of
     * three or four sounds may be one sound off only when it is the whole
     * answer; longer words may be one sound off (two from seven) anywhere
     * in it. Words of under three sounds must be said exactly.
     *
     * @param  list<string>  $phrases
     */
    private function soundsLike(string $normalized, array $phrases): bool
    {
        $wholeAnswer = TranscriptNormalizer::soundKey($normalized);
        $words = explode(' ', $normalized);
        $parts = [];

        foreach ($words as $position => $word) {
            $parts[] = TranscriptNormalizer::soundKey($word);

            if (isset($words[$position + 1])) {
                $parts[] = TranscriptNormalizer::soundKey($word.$words[$position + 1]);
            }
        }

        foreach ($phrases as $phrase) {
            $isWholeOnly = str_starts_with($phrase, '=');
            $key = TranscriptNormalizer::soundKey($isWholeOnly ? substr($phrase, 1) : $phrase);
            $length = strlen($key);

            if ($length < 3) {
                continue;
            }

            if ($length <= 4 || $isWholeOnly) {
                if (levenshtein($wholeAnswer, $key) <= 1) {
                    return true;
                }

                continue;
            }

            $allowed = $length >= 7 ? 2 : 1;

            foreach ([$wholeAnswer, ...$parts] as $part) {
                if (levenshtein($part, $key) <= $allowed) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Total length of the words found in the transcript.
     *
     * @param  list<string>  $phrases
     */
    private function score(string $normalized, array $phrases): int
    {
        $score = 0;

        foreach ($phrases as $phrase) {
            if (str_starts_with($phrase, '=')) {
                $whole = TranscriptNormalizer::normalize(substr($phrase, 1));
                $score += $normalized === $whole ? mb_strlen($whole) : 0;

                continue;
            }

            $word = TranscriptNormalizer::normalize($phrase);

            if ($word !== '' && preg_match('/(?:^|\s)'.preg_quote($word, '/').'(?:$|\s)/u', $normalized) === 1) {
                $score += mb_strlen($word);
            }
        }

        return $score;
    }
}
