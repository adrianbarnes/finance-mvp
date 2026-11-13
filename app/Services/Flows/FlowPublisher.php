<?php

namespace App\Services\Flows;

use App\Enums\FlowStatus;
use App\Enums\PromptSlot;
use App\Enums\StepType;
use App\Models\AudioClip;
use App\Models\CallFlow;
use App\Models\ClipRecording;
use App\Models\FlowStep;
use App\Models\Language;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Publishes the draft flow so new calls use it, and keeps a draft to edit.
 * Publishing archives the previous version; calls already in progress
 * keep the version they started on. Recordings are copied with the flow,
 * so a draft upload only reaches callers once it is published.
 */
class FlowPublisher
{
    public function __construct(private DefaultCallFlow $defaultCallFlow) {}

    /**
     * The version new calls use. On a fresh install, today's call is
     * published as version 1.
     */
    public function live(): CallFlow
    {
        $published = CallFlow::query()->where('status', FlowStatus::Published)->latest('version')->first();

        if ($published !== null) {
            return $published;
        }

        return DB::transaction(function (): CallFlow {
            $flow = CallFlow::draft() ?? $this->defaultCallFlow->create();
            $flow->update(['status' => FlowStatus::Published, 'published_at' => now()]);

            return $flow;
        });
    }

    /**
     * The draft to edit: an existing draft, or a copy of the live version.
     */
    public function draft(): CallFlow
    {
        return CallFlow::draft() ?? $this->copy($this->live());
    }

    /**
     * Reasons the draft cannot be published yet.
     *
     * @return list<string>
     */
    public function problems(CallFlow $draft): array
    {
        $steps = $draft->steps()->with(['promptItems.clip.recordings', 'children'])->get();
        $languages = Language::enabledInOrder();
        $problems = [];

        if ($steps->whereNull('parent_id')->isEmpty()) {
            $problems[] = $this->problem('The flow has no steps.');
        }

        foreach ($steps as $step) {
            $name = "\"{$step->name}\"";
            $options = $step->children->where('is_otherwise', false);
            $digits = $options->pluck('keypad_digit')->reject(fn (?string $digit): bool => $digit === null);

            $stepProblems = [
                ':step has no options.' => $step->type === StepType::Choice && $options->isEmpty(),
                ':step uses the same key for two options.' => $step->type === StepType::Choice && $digits->count() !== $digits->unique()->count(),
                ':step does not say which step to go to.' => $step->type === StepType::GoTo && $step->go_to_step_id === null,
                ':step does not say what to do.' => $step->type === StepType::Do && blank($step->configValue('action')),
                ':step does not say what to save the answer as.' => in_array($step->type, [StepType::AskNumber, StepType::AskSpeech], true) && blank($step->configValue('variable')),
                ':step has no recording in any language.' => $step->type->speaks() && $languages->isNotEmpty() && ! $this->hasAudio($step, $languages),
            ];

            foreach ($stepProblems as $message => $applies) {
                if ($applies) {
                    $problems[] = $this->problem($message, $name);
                }
            }
        }

        return $problems;
    }

    /**
     * Publish the draft and start a new draft from it.
     *
     * @return list<string> Problems that stopped publishing; empty when published
     */
    public function publish(CallFlow $draft): array
    {
        abort_unless($draft->status === FlowStatus::Draft, 422);

        $problems = $this->problems($draft);

        if ($problems !== []) {
            return $problems;
        }

        DB::transaction(function () use ($draft): void {
            CallFlow::query()->where('status', FlowStatus::Published)->update(['status' => FlowStatus::Archived]);
            $draft->update(['status' => FlowStatus::Published, 'published_at' => now()]);
            $this->copy($draft);
        });

        return [];
    }

    /**
     * Throw away the draft's changes and start again from the live version.
     */
    public function discardDraft(): CallFlow
    {
        return DB::transaction(function (): CallFlow {
            $live = $this->live();
            CallFlow::query()->where('status', FlowStatus::Draft)->delete();

            return $this->copy($live);
        });
    }

    /**
     * Replace the draft with a copy of an earlier version, to check, test
     * and publish it like any other draft.
     */
    public function restoreIntoDraft(CallFlow $version): CallFlow
    {
        abort_if($version->status === FlowStatus::Draft, 422, 'This version is the draft already.');

        return DB::transaction(function () use ($version): CallFlow {
            CallFlow::query()->where('status', FlowStatus::Draft)->delete();

            return $this->copy($version);
        });
    }

    /**
     * Make an archived version live again straight away, as a new version
     * number. The draft is left as it is.
     *
     * @return list<string> Problems that stopped publishing; empty when published
     */
    public function republish(CallFlow $version): array
    {
        abort_unless($version->status === FlowStatus::Archived, 422, 'Only an archived version can be published again.');

        $problems = $this->problems($version);

        if ($problems !== []) {
            return $problems;
        }

        DB::transaction(function () use ($version): void {
            $copy = $this->copy($version);
            CallFlow::query()->where('status', FlowStatus::Published)->update(['status' => FlowStatus::Archived]);
            $copy->update(['status' => FlowStatus::Published, 'published_at' => now()]);
        });

        return [];
    }

    /**
     * Delete archived versions (all, or the ones given) and any audio files
     * no remaining version uses. Past calls keep their transcripts and
     * recordings but lose the link to the deleted version.
     *
     * @param  list<int>|null  $versionIds  Null for every archived version
     * @return int The number of versions deleted
     */
    public function purge(?array $versionIds = null): int
    {
        $versions = CallFlow::query()
            ->where('status', FlowStatus::Archived)
            ->when($versionIds !== null, fn ($query) => $query->whereKey($versionIds))
            ->get();

        abort_if($versionIds !== null && $versions->count() !== count($versionIds), 422, 'Only archived versions can be deleted.');

        DB::transaction(fn () => $versions->each->delete());
        $this->deleteUnusedAudio();

        return $versions->count();
    }

    /**
     * Delete stored recordings that no version refers to any more.
     */
    public function deleteUnusedAudio(): int
    {
        $disk = Storage::disk('public');
        $inUse = [];

        foreach (ClipRecording::query()->get(['audio_path', 'telephony_audio_path']) as $recording) {
            foreach ([$recording->audio_path, $recording->telephony_audio_path] as $path) {
                if ($path !== null) {
                    $inUse[$path] = true;
                }
            }
        }

        $unused = array_values(array_filter(
            $disk->files(ClipRecordingStore::Folder),
            fn (string $path): bool => ! isset($inUse[$path]),
        ));

        $disk->delete($unused);

        return count($unused);
    }

    /**
     * Copy a version into a new draft: steps, keywords, clips and their
     * recordings. Audio files are shared, never duplicated.
     */
    public function copy(CallFlow $source): CallFlow
    {
        return DB::transaction(function () use ($source): CallFlow {
            $draft = CallFlow::query()->create([
                'version' => (int) CallFlow::query()->max('version') + 1,
                'status' => FlowStatus::Draft,
                'settings' => $source->settings,
            ]);

            $clipIds = [];

            foreach ($source->clips()->with('recordings')->get() as $clip) {
                $copy = $draft->clips()->create($clip->only(['key', 'group']));
                $clipIds[$clip->id] = $copy->id;

                foreach ($clip->recordings as $recording) {
                    $copy->recordings()->create($recording->only(['language_id', 'text', 'audio_path', 'telephony_audio_path', 'original_filename', 'duration_ms']));
                }
            }

            $steps = $source->steps()->with(['keywords', 'promptItems'])->orderBy('position')->get();
            $stepIds = [];
            $queue = $steps->whereNull('parent_id')->values()->all();

            while ($queue !== []) {
                /** @var FlowStep $step */
                $step = array_shift($queue);
                $copy = $draft->steps()->create([
                    ...$step->only(['position', 'type', 'name', 'keypad_digit', 'config', 'is_otherwise']),
                    'parent_id' => $step->parent_id === null ? null : $stepIds[$step->parent_id],
                ]);
                $stepIds[$step->id] = $copy->id;

                foreach ($step->keywords as $keyword) {
                    $copy->keywords()->create($keyword->only(['language_id', 'words']));
                }

                foreach ($step->promptItems as $item) {
                    $copy->promptItems()->create([
                        ...$item->only(['slot', 'position', 'placeholder']),
                        'audio_clip_id' => $item->audio_clip_id === null ? null : $clipIds[$item->audio_clip_id],
                    ]);
                }

                array_push($queue, ...$steps->where('parent_id', $step->id)->values()->all());
            }

            foreach ($steps->whereNotNull('go_to_step_id') as $step) {
                FlowStep::query()->whereKey($stepIds[$step->id])->update(['go_to_step_id' => $stepIds[$step->go_to_step_id] ?? null]);
            }

            return $draft;
        });
    }

    private function problem(string $message, string $step = ''): string
    {
        $translated = __($message, ['step' => $step]);

        return is_string($translated) ? $translated : $message;
    }

    /**
     * @param  Collection<int, Language>  $languages
     */
    private function hasAudio(FlowStep $step, Collection $languages): bool
    {
        $clips = $step->promptItemsFor(PromptSlot::Prompt)->pluck('clip')->filter();

        if ($step->promptItemsFor(PromptSlot::Prompt)->isEmpty()) {
            return false;
        }

        return $clips->isEmpty() || $languages->contains(fn (Language $language): bool => $clips->every(fn (AudioClip $clip): bool => $clip->hasAudioIn($language)));
    }
}
