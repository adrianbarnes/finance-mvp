<?php

namespace App\Livewire\CallFlows;

use App\Enums\BranchMatch;
use App\Enums\CheckCondition;
use App\Enums\NumberUnit;
use App\Enums\Placeholder;
use App\Enums\PromptSlot;
use App\Enums\StepAction;
use App\Enums\StepType;
use App\Models\AudioClip;
use App\Models\CallFlow;
use App\Models\FlowStep;
use App\Models\Language;
use App\Models\StepPromptItem;
use App\Services\Audio\AudioTranscodingFailed;
use App\Services\Flows\ClipRecordingStore;
use App\Services\Flows\FlowEditor;
use App\Services\Flows\FlowPublisher;
use App\Services\Flows\PromptAudioResolver;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The visual IVR builder: the flow drawn as a top-down flowchart. Steps
 * can be added, moved and removed anywhere, and each step's audio is
 * uploaded per language, chosen with the language switcher.
 *
 * @property-read CallFlow $flow
 * @property-read CallFlow $liveFlow
 * @property-read EloquentCollection<int, CallFlow> $versions
 * @property-read Collection<int, Language> $languages
 * @property-read Language $language
 * @property-read EloquentCollection<int, FlowStep> $steps
 * @property-read Collection<int|string, Collection<int, FlowStep>> $stepsByParent
 * @property-read FlowStep|null $selectedStep
 * @property-read array<int, array{state: string, language: Language|null}> $audioStatuses
 * @property-read EloquentCollection<int, AudioClip> $clips
 * @property-read list<string> $answerNames
 */
#[Title('IVR flow')]
class Builder extends Component
{
    use WithFileUploads;

    public string $languageCode = '';

    public string $tab = 'flow';

    public ?int $selectedStepId = null;

    public string $stepName = '';

    public string $keypadDigit = '';

    public string $branchMatch = 'keywords';

    public string $keywords = '';

    public string $condition = '';

    public string $action = '';

    public string $variable = '';

    public string $min = '';

    public string $max = '';

    public string $unit = 'plain';

    /**
     * Why the last attempt to publish was refused.
     *
     * @var list<string>
     */
    public array $publishProblems = [];

    public string $goToStepId = '';

    /**
     * Choices and checks whose branches are folded away in the flowchart.
     *
     * @var list<int>
     */
    public array $collapsedStepIds = [];

    public string $maxNoInputReplays = '2';

    public string $maxMisunderstoodAttempts = '3';

    /**
     * Wording of each clip in the selected language, keyed by clip ID.
     *
     * @var array<int|string, string>
     */
    public array $recordingTexts = [];

    /**
     * Audio files being uploaded, keyed by clip ID.
     *
     * @var array<int|string, mixed>
     */
    public array $uploads = [];

    public function mount(): void
    {
        $this->languageCode = Language::primary()->code;
        $this->maxNoInputReplays = (string) $this->flow->setting('max_no_input_replays');
        $this->maxMisunderstoodAttempts = (string) $this->flow->setting('max_misunderstood_attempts');
        $this->loadRecordingTexts();
        $this->collapseAll();
    }

    /**
     * The draft being edited: a copy of the live version until published.
     */
    #[Computed]
    public function flow(): CallFlow
    {
        return app(FlowPublisher::class)->draft();
    }

    /**
     * The version calls use now.
     */
    #[Computed]
    public function liveFlow(): CallFlow
    {
        return app(FlowPublisher::class)->live();
    }

    /**
     * Every version, newest first, with how many calls ran it.
     *
     * @return EloquentCollection<int, CallFlow>
     */
    #[Computed]
    public function versions(): EloquentCollection
    {
        return CallFlow::query()->withCount('callSessions')->orderByDesc('version')->get();
    }

    /**
     * Replace the draft with a copy of an earlier version.
     */
    public function restoreVersion(int $versionId, FlowPublisher $publisher): void
    {
        $version = CallFlow::query()->findOrFail($versionId);
        $publisher->restoreIntoDraft($version);

        $this->publishProblems = [];
        $this->startOver();
        $this->tab = 'flow';
        Flux::toast(variant: 'success', text: __('Version :version copied into the draft. Test it, then publish.', ['version' => $version->version]));
    }

    /**
     * Make an archived version live again straight away.
     */
    public function republishVersion(int $versionId, FlowPublisher $publisher): void
    {
        $version = CallFlow::query()->findOrFail($versionId);
        $this->publishProblems = $publisher->republish($version);

        if ($this->publishProblems !== []) {
            Flux::toast(variant: 'danger', text: __('Version :version cannot be published again. Fix the problems listed in a draft copy.', ['version' => $version->version]));

            return;
        }

        unset($this->liveFlow, $this->versions);
        Flux::toast(variant: 'success', text: __('Version :version is live again, as version :new.', ['version' => $version->version, 'new' => $this->liveFlow->version]));
    }

    public function deleteVersion(int $versionId, FlowPublisher $publisher): void
    {
        $publisher->purge([$versionId]);
        unset($this->versions);
        Flux::toast(text: __('Version deleted.'));
    }

    public function purgeArchived(FlowPublisher $publisher): void
    {
        $deleted = $publisher->purge();
        unset($this->versions);
        Flux::toast(text: trans_choice(':count archived version deleted.|:count archived versions deleted.', $deleted));
    }

    public function publish(FlowPublisher $publisher): void
    {
        $version = $this->flow->version;
        $this->publishProblems = $publisher->publish($this->flow);

        if ($this->publishProblems !== []) {
            Flux::toast(variant: 'danger', text: __('Not published. Fix the problems listed, then publish again.'));

            return;
        }

        $this->startOver();
        Flux::toast(variant: 'success', text: __('Version :version is live. New calls use it; calls in progress finish on the version they started on.', ['version' => $version]));
    }

    public function discardDraft(FlowPublisher $publisher): void
    {
        $publisher->discardDraft();
        $this->publishProblems = [];
        $this->startOver();
        Flux::toast(text: __('Draft changes discarded. The draft is a fresh copy of the live version.'));
    }

    /**
     * @return Collection<int, Language>
     */
    #[Computed]
    public function languages(): Collection
    {
        return Language::enabledInOrder()->toBase();
    }

    #[Computed]
    public function language(): Language
    {
        return Language::query()->where('code', $this->languageCode)->firstOrFail();
    }

    /**
     * @return EloquentCollection<int, FlowStep>
     */
    #[Computed]
    public function steps(): EloquentCollection
    {
        return $this->flow->steps()
            ->with(['keywords', 'promptItems.clip.recordings', 'goToStep'])
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * Steps grouped by the branch they sit in ("root" for the top level).
     *
     * @return Collection<int|string, Collection<int, FlowStep>>
     */
    #[Computed]
    public function stepsByParent(): Collection
    {
        return $this->steps->toBase()->groupBy(fn (FlowStep $step): int|string => $step->parent_id ?? 'root');
    }

    #[Computed]
    public function selectedStep(): ?FlowStep
    {
        return $this->selectedStepId === null ? null : $this->steps->firstWhere('id', $this->selectedStepId);
    }

    /**
     * How each step sounds in the selected language.
     *
     * @return array<int, array{state: string, language: Language|null}>
     */
    #[Computed]
    public function audioStatuses(): array
    {
        $resolver = app(PromptAudioResolver::class);

        return $this->steps
            ->mapWithKeys(fn (FlowStep $step): array => [$step->id => $resolver->status($step, $this->language, $this->languages)])
            ->all();
    }

    /**
     * @return EloquentCollection<int, AudioClip>
     */
    #[Computed]
    public function clips(): EloquentCollection
    {
        return $this->flow->clips()->with('recordings')->withCount('promptItems')->orderBy('group')->orderBy('key')->get();
    }

    /**
     * Names of the answers saved by "ask" steps, for use as values.
     *
     * @return list<string>
     */
    #[Computed]
    public function answerNames(): array
    {
        $names = [];

        foreach ($this->steps as $step) {
            $name = $step->configValue('variable');

            if (in_array($step->type, [StepType::AskNumber, StepType::AskSpeech], true) && is_string($name) && $name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function updatedLanguageCode(): void
    {
        unset($this->language, $this->audioStatuses);
        $this->loadRecordingTexts();

        if ($this->selectedStep !== null) {
            $this->keywords = implode(', ', $this->selectedStep->keywordsFor($this->language));
        }
    }

    public function selectStep(int $stepId): void
    {
        $step = $this->findStep($stepId);

        $this->selectedStepId = $step->id;
        $this->stepName = $step->name;
        $this->keypadDigit = (string) $step->keypad_digit;
        $this->branchMatch = (string) $step->configValue('match', BranchMatch::Keywords->value);
        $this->keywords = implode(', ', $step->keywordsFor($this->language));
        $this->condition = (string) $step->configValue('condition', '');
        $this->action = (string) $step->configValue('action', '');
        $this->variable = (string) $step->configValue('variable', '');
        $this->min = (string) $step->configValue('min', '');
        $this->max = (string) $step->configValue('max', '');
        $this->unit = (string) $step->configValue('unit', NumberUnit::Plain->value);
        $this->goToStepId = (string) $step->go_to_step_id;
        $this->resetValidation();
        unset($this->selectedStep);
    }

    public function closePanel(): void
    {
        $this->selectedStepId = null;
        unset($this->selectedStep);
    }

    public function toggleBranches(int $stepId): void
    {
        $this->collapsedStepIds = in_array($stepId, $this->collapsedStepIds, true)
            ? array_values(array_diff($this->collapsedStepIds, [$stepId]))
            : [...$this->collapsedStepIds, $stepId];
    }

    /**
     * Fold every choice and check except those at the top level.
     */
    public function collapseAll(): void
    {
        $this->collapsedStepIds = [];

        foreach ($this->steps as $step) {
            if ($step->type->hasBranches() && $step->parent_id !== null) {
                $this->collapsedStepIds[] = $step->id;
            }
        }
    }

    public function expandAll(): void
    {
        $this->collapsedStepIds = [];
    }

    public function addStep(?int $branchId, int $position, string $kind, FlowEditor $editor): void
    {
        $branch = $branchId === null ? null : $this->findStep($branchId);
        abort_unless($kind === FlowEditor::YesNoQuestion || in_array(StepType::tryFrom($kind), StepType::addable(), true), 422);

        $step = $editor->addStep($this->flow, $branch, $position, $kind);
        $this->refreshFlow();
        $this->selectStep($step->id);
    }

    public function addBranch(int $stepId, FlowEditor $editor): void
    {
        $branch = $editor->addBranch($this->findStep($stepId));
        $this->refreshFlow();
        $this->selectStep($branch->id);
    }

    /**
     * Drag-and-drop handler for steps: $group is "root" or a branch ID.
     */
    public function moveStep(int $stepId, int $position, string $group, FlowEditor $editor): void
    {
        $target = $group === 'root' ? null : $this->findStep((int) $group);

        try {
            $editor->moveStep($this->findStep($stepId), $target, $position);
        } catch (InvalidArgumentException) {
            Flux::toast(variant: 'danger', text: __('A step cannot be moved inside itself.'));
        }

        $this->refreshFlow();
    }

    public function moveBranch(int $branchId, int $position, FlowEditor $editor): void
    {
        $editor->moveBranch($this->findStep($branchId), $position);
        $this->refreshFlow();
    }

    public function deleteStep(int $stepId, FlowEditor $editor): void
    {
        $step = $this->findStep($stepId);
        $parent = $step->parent;

        if ($step->type === StepType::Branch && ($step->is_otherwise || $parent?->type === StepType::Check)) {
            Flux::toast(variant: 'danger', text: __('This branch is needed by its step. Delete the step instead.'));

            return;
        }

        $editor->delete($step);
        $this->closePanel();
        $this->refreshFlow();
        Flux::toast(text: __('Step deleted.'));
    }

    public function saveStep(): void
    {
        $step = $this->findStep((int) $this->selectedStepId);
        $parentType = $step->parent?->type;
        $isChoiceBranch = $step->type === StepType::Branch && $parentType === StepType::Choice && ! $step->is_otherwise;

        $this->validate([
            'stepName' => ['required', 'string', 'max:80'],
            'keypadDigit' => $isChoiceBranch ? ['nullable', 'regex:/^[0-9]$/', function (string $attribute, mixed $value, \Closure $fail) use ($step): void {
                if ($value !== '' && FlowStep::query()->where('parent_id', $step->parent_id)->whereKeyNot($step->id)->where('keypad_digit', $value)->exists()) {
                    $fail(__('Another option already uses key :key.', ['key' => $value]));
                }
            }] : [],
            'branchMatch' => $isChoiceBranch ? ['required', Rule::enum(BranchMatch::class)] : [],
            'keywords' => ['nullable', 'string', 'max:2000'],
            'condition' => $step->type === StepType::Check ? ['required', Rule::enum(CheckCondition::class)] : [],
            'action' => $step->type === StepType::Do ? ['required', Rule::enum(StepAction::class)] : [],
            'variable' => in_array($step->type, [StepType::AskNumber, StepType::AskSpeech], true) ? ['required', 'regex:/^[a-z][a-z0-9_]*$/', 'max:30'] : [],
            'min' => $step->type === StepType::AskNumber ? ['required', 'integer', 'min:0'] : [],
            'max' => $step->type === StepType::AskNumber ? ['required', 'integer', 'gte:min'] : [],
            'unit' => $step->type === StepType::AskNumber ? ['required', Rule::enum(NumberUnit::class)] : [],
            'goToStepId' => $step->type === StepType::GoTo ? ['required', Rule::exists('flow_steps', 'id')->where('call_flow_id', $this->flow->id)->whereNot('type', StepType::Branch->value)] : [],
        ], [
            'variable.regex' => __('Use lowercase letters, numbers and underscores, e.g. amount.'),
            'keypadDigit.regex' => __('Use a single key from 0 to 9.'),
        ]);

        $step->update([
            'name' => $this->stepName,
            'keypad_digit' => $isChoiceBranch ? ($this->keypadDigit ?: null) : $step->keypad_digit,
            'go_to_step_id' => $step->type === StepType::GoTo ? (int) $this->goToStepId : $step->go_to_step_id,
            'config' => [...($step->config ?? []), ...match ($step->type) {
                StepType::Branch => $isChoiceBranch ? ['match' => $this->branchMatch] : [],
                StepType::Check => ['condition' => $this->condition],
                StepType::Do => ['action' => $this->action],
                StepType::AskNumber => ['variable' => $this->variable, 'unit' => $this->unit, 'min' => (int) $this->min, 'max' => (int) $this->max],
                StepType::AskSpeech => ['variable' => $this->variable],
                default => [],
            }],
        ]);

        if ($isChoiceBranch) {
            $words = array_values(array_unique(array_filter(array_map(
                fn (string $word): string => Str::lower(trim($word)),
                preg_split('/[,\n]+/', $this->keywords) ?: [],
            ))));

            $step->keywords()->updateOrCreate(['language_id' => $this->language->id], ['words' => $words]);
        }

        $this->refreshFlow();
        Flux::toast(variant: 'success', text: __('Step saved.'));
    }

    /**
     * Add a piece to what the selected step says: a new recording, an
     * existing clip, or a value spoken at call time.
     */
    public function addPiece(string $slot, string $piece, FlowEditor $editor): void
    {
        $step = $this->findStep((int) $this->selectedStepId);
        $promptSlot = PromptSlot::from($slot);
        abort_unless(in_array($promptSlot, PromptSlot::forType($step->type), true), 422);

        if ($piece === 'new') {
            $editor->addClipItem($step, $promptSlot, $step->name);
        } else {
            [$kind, $value] = explode(':', $piece, 2) + [1 => ''];
            $clipId = $kind === 'clip' ? $this->flow->clips()->whereKey((int) $value)->value('id') : null;
            $placeholder = match ($kind) {
                'value' => Placeholder::from($value)->value,
                'answer' => in_array($value, $this->answerNames, true) ? 'answer:'.$value : null,
                default => null,
            };
            abort_if($clipId === null && $placeholder === null, 422);

            $step->promptItems()->create([
                'slot' => $promptSlot,
                'position' => (int) $step->promptItems()->where('slot', $promptSlot)->max('position') + 1,
                'audio_clip_id' => $clipId,
                'placeholder' => $placeholder,
            ]);
        }

        $this->refreshFlow();
        $this->loadRecordingTexts();
    }

    public function movePiece(int $itemId, int $direction): void
    {
        $item = $this->findPromptItem($itemId);
        $pieces = StepPromptItem::query()->where('flow_step_id', $item->flow_step_id)->where('slot', $item->slot)->orderBy('position')->orderBy('id')->get()->values();
        $index = $pieces->search(fn (StepPromptItem $piece): bool => $piece->is($item));
        $swapWith = $pieces->get($index + ($direction < 0 ? -1 : 1));

        if ($swapWith !== null) {
            $reordered = $pieces->all();
            [$reordered[$index], $reordered[$index + ($direction < 0 ? -1 : 1)]] = [$swapWith, $item];

            foreach (array_values($reordered) as $position => $piece) {
                $piece->update(['position' => $position]);
            }
        }

        $this->refreshFlow();
    }

    public function removePiece(int $itemId): void
    {
        $this->findPromptItem($itemId)->delete();
        $this->refreshFlow();
    }

    /**
     * Store an uploaded recording for the selected language as soon as the
     * file arrives.
     */
    public function updatedUploads(mixed $file, string $clipId): void
    {
        $store = app(ClipRecordingStore::class);
        $this->validate(['uploads.'.$clipId => ['file', 'max:10240', 'mimetypes:audio/mpeg,audio/wav,audio/x-wav,audio/wave,audio/ogg,audio/webm,video/webm,audio/mp4,audio/x-m4a,audio/aac,audio/flac,audio/x-flac']]);

        $clip = $this->findClip((int) $clipId);

        if (! $file instanceof UploadedFile) {
            return;
        }

        try {
            $store->store($clip, $this->language, (string) $file->get(), $file->getClientOriginalName());
        } catch (AudioTranscodingFailed) {
            $this->addError('uploads.'.$clipId, __('The recording could not be converted. Try a WAV or MP3 file.'));

            return;
        }

        unset($this->uploads[$clipId]);
        $this->refreshFlow();
        Flux::toast(variant: 'success', text: __(':language recording saved for :clip.', ['language' => $this->language->name, 'clip' => $clip->key]));
    }

    public function updatedRecordingTexts(mixed $text, string $clipId): void
    {
        $this->findClip((int) $clipId)->recordings()->updateOrCreate(
            ['language_id' => $this->language->id],
            ['text' => Str::limit(trim((string) $text), 2000, '') ?: null],
        );

        $this->refreshFlow();
    }

    public function removeRecording(int $clipId, ClipRecordingStore $store): void
    {
        $recording = $this->findClip($clipId)->recordingFor($this->language);

        if ($recording !== null) {
            $store->remove($recording);
        }

        $this->refreshFlow();
        Flux::toast(text: __('Recording removed.'));
    }

    public function saveSettings(): void
    {
        $this->validate([
            'maxNoInputReplays' => ['required', 'integer', 'min:0', 'max:5'],
            'maxMisunderstoodAttempts' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $this->flow->update(['settings' => [
            ...($this->flow->settings ?? []),
            'max_no_input_replays' => (int) $this->maxNoInputReplays,
            'max_misunderstood_attempts' => (int) $this->maxMisunderstoodAttempts,
        ]]);

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function render(): View
    {
        return view('livewire.call-flows.builder', [
            'addableKinds' => [
                ...array_map(fn (StepType $type): array => ['value' => $type->value, 'label' => $type->label(), 'icon' => $type->icon()], StepType::addable()),
                ['value' => FlowEditor::YesNoQuestion, 'label' => __('Yes/no question'), 'icon' => 'check-circle'],
            ],
            'conditions' => CheckCondition::cases(),
            'actions' => StepAction::cases(),
            'branchMatches' => BranchMatch::cases(),
            'placeholders' => Placeholder::cases(),
            'units' => NumberUnit::cases(),
        ]);
    }

    private function findStep(int $stepId): FlowStep
    {
        return $this->flow->steps()->findOrFail($stepId);
    }

    private function findClip(int $clipId): AudioClip
    {
        return $this->flow->clips()->with('recordings')->findOrFail($clipId);
    }

    private function findPromptItem(int $itemId): StepPromptItem
    {
        return StepPromptItem::query()
            ->whereKey($itemId)
            ->whereHas('step', fn ($query) => $query->where('call_flow_id', $this->flow->id))
            ->firstOrFail();
    }

    /**
     * Reload everything after the draft was replaced.
     */
    private function startOver(): void
    {
        unset($this->flow, $this->liveFlow, $this->versions);
        $this->closePanel();
        $this->refreshFlow();
        $this->maxNoInputReplays = (string) $this->flow->setting('max_no_input_replays');
        $this->maxMisunderstoodAttempts = (string) $this->flow->setting('max_misunderstood_attempts');
        $this->loadRecordingTexts();
        $this->collapseAll();
    }

    private function refreshFlow(): void
    {
        unset($this->steps, $this->stepsByParent, $this->selectedStep, $this->audioStatuses, $this->clips, $this->answerNames);
    }

    private function loadRecordingTexts(): void
    {
        $this->recordingTexts = $this->clips
            ->mapWithKeys(fn (AudioClip $clip): array => [$clip->id => (string) $clip->recordingFor($this->language)?->text])
            ->all();
    }
}
