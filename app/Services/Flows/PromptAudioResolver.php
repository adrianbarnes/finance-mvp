<?php

namespace App\Services\Flows;

use App\Enums\PromptSlot;
use App\Enums\StepType;
use App\Models\AudioClip;
use App\Models\FlowStep;
use App\Models\Language;
use Illuminate\Support\Collection;

/**
 * Picks the language a step's audio is played in. A step never mixes
 * languages: it plays in the caller's language when every clip in it has
 * a recording there, otherwise the primary language, otherwise the first
 * language (in order) that has them all. The language choice is the
 * exception: each language offers itself in its own language.
 */
class PromptAudioResolver
{
    /**
     * @param  Collection<int, Language>  $languagesInOrder  Enabled languages, primary included
     */
    public function languageFor(FlowStep $step, PromptSlot $slot, Language $callLanguage, Collection $languagesInOrder): ?Language
    {
        $clips = $step->promptItemsFor($slot)->pluck('clip')->filter()->values();

        if ($step->type === StepType::ChooseLanguage) {
            return $clips->every->hasAudioIn($callLanguage) ? $callLanguage : null;
        }

        return $this->languageForClips($clips, $callLanguage, $languagesInOrder);
    }

    /**
     * @param  Collection<int, AudioClip>  $clips
     * @param  Collection<int, Language>  $languagesInOrder
     */
    public function languageForClips(Collection $clips, Language $callLanguage, Collection $languagesInOrder): ?Language
    {
        if ($clips->isEmpty()) {
            return $callLanguage;
        }

        return collect([$callLanguage, $languagesInOrder->firstWhere('is_primary', true)])
            ->filter()
            ->concat($languagesInOrder)
            ->first(fn (Language $language): bool => $clips->every->hasAudioIn($language));
    }

    /**
     * How a step sounds in a language, for the builder: "own" audio,
     * "fallback" to another language, "missing" audio, or "silent" for
     * steps that say nothing.
     *
     * @param  Collection<int, Language>  $languagesInOrder
     * @return array{state: string, language: Language|null}
     */
    public function status(FlowStep $step, Language $language, Collection $languagesInOrder): array
    {
        $items = $step->promptItemsFor(PromptSlot::Prompt);

        if ($items->isEmpty()) {
            return ['state' => $step->type->speaks() ? 'missing' : 'silent', 'language' => null];
        }

        $playedIn = $this->languageFor($step, PromptSlot::Prompt, $language, $languagesInOrder);

        return match (true) {
            $playedIn === null => ['state' => 'missing', 'language' => null],
            $playedIn->is($language) => ['state' => 'own', 'language' => $language],
            default => ['state' => 'fallback', 'language' => $playedIn],
        };
    }
}
