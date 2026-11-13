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

        $best = null;
        $bestScore = 0;

        foreach ($branches->where('is_otherwise', false) as $branch) {
            $words = match (BranchMatch::tryFrom((string) $branch->configValue('match'))) {
                BranchMatch::Yes => $language->yes_words,
                BranchMatch::No => $language->no_words,
                default => $branch->keywordsFor($language),
            };

            $score = $this->score($normalized, $words);

            if ($score > $bestScore) {
                $best = $branch;
                $bestScore = $score;
            }
        }

        return $best;
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

        foreach (Language::enabledInOrder() as $language) {
            if ($this->score($normalized, $language->selection_words) > 0) {
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
        $digits = rtrim($digits, '#');

        if (! ctype_digit($digits) || (int) $digits < 1) {
            return null;
        }

        return Language::enabledInOrder()->get((int) $digits - 1);
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
