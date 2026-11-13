<?php

use App\Enums\PromptSlot;
use App\Enums\StepType;
use App\Models\AudioClip;
use App\Models\CallFlow;
use App\Models\ClipRecording;
use App\Models\FlowStep;
use App\Models\Language;
use App\Services\Flows\PromptAudioResolver;

/**
 * A step saying two clips, each recorded in the given languages.
 *
 * @param  list<string>  $firstClipLanguages
 * @param  list<string>  $secondClipLanguages
 */
function stepWithRecordings(array $firstClipLanguages, array $secondClipLanguages, StepType $type = StepType::Message): FlowStep
{
    $flow = CallFlow::factory()->create();
    $step = FlowStep::factory()->for($flow, 'flow')->ofType($type)->create();

    foreach ([$firstClipLanguages, $secondClipLanguages] as $position => $codes) {
        $clip = AudioClip::factory()->for($flow, 'flow')->create();
        $step->promptItems()->create(['slot' => PromptSlot::Prompt, 'position' => $position, 'audio_clip_id' => $clip->id]);

        foreach ($codes as $code) {
            ClipRecording::factory()->for($clip, 'clip')->create(['language_id' => language($code)->id]);
        }
    }

    return $step->load('promptItems.clip.recordings');
}

function playedIn(FlowStep $step, string $callerLanguage): ?string
{
    return app(PromptAudioResolver::class)
        ->languageFor($step, PromptSlot::Prompt, language($callerLanguage), Language::enabledInOrder())
        ?->code;
}

test('plays the caller\'s language when every clip is recorded in it', function () {
    expect(playedIn(stepWithRecordings(['ewe', 'twi'], ['ewe']), 'ewe'))->toBe('ewe');
});

test('falls back to the primary language for the whole step, never mixing languages', function () {
    expect(playedIn(stepWithRecordings(['ewe', 'twi'], ['twi']), 'ewe'))->toBe('twi');
});

test('falls back to the first language in order that has every clip', function () {
    language('eng')->update(['position' => 0]);

    expect(playedIn(stepWithRecordings(['ewe', 'eng'], ['ewe', 'eng']), 'twi'))->toBe('eng');
});

test('finds no language when no language has every clip', function () {
    expect(playedIn(stepWithRecordings(['ewe'], ['twi']), 'ewe'))->toBeNull();
});

test('offers a language on the language choice only with its own recording', function () {
    $step = stepWithRecordings(['twi'], ['twi'], StepType::ChooseLanguage);

    expect(playedIn($step, 'twi'))->toBe('twi')
        ->and(playedIn($step, 'ewe'))->toBeNull();
});

test('describes how a step sounds in a language for the builder', function () {
    $resolver = app(PromptAudioResolver::class);
    $languages = Language::enabledInOrder();

    expect($resolver->status(stepWithRecordings(['ewe'], ['ewe']), language('ewe'), $languages)['state'])->toBe('own')
        ->and($resolver->status(stepWithRecordings(['twi'], ['twi']), language('ewe'), $languages)['language']->code)->toBe('twi')
        ->and($resolver->status(stepWithRecordings([], []), language('ewe'), $languages)['state'])->toBe('missing')
        ->and($resolver->status(FlowStep::factory()->ofType(StepType::EndCall)->create(), language('ewe'), $languages)['state'])->toBe('silent');
});
