<?php

namespace App\Services\Flows;

use App\Enums\BranchMatch;
use App\Enums\CheckCondition;
use App\Enums\PromptSlot;
use App\Enums\StepType;
use App\Models\AudioClip;
use App\Models\CallFlow;
use App\Models\FlowStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Changes the shape of a flow: adding, moving and deleting steps and
 * branches. Steps sit at the top level or inside a branch; branches sit
 * inside a choice or a check.
 */
class FlowEditor
{
    /**
     * Kinds offered when adding a step: every step type, plus a yes/no
     * question (a choice that starts with Yes and No branches).
     */
    public const string YesNoQuestion = 'yes_no';

    public function addStep(CallFlow $flow, ?FlowStep $parentBranch, int $position, string $kind): FlowStep
    {
        $this->ensureCanHoldSteps($parentBranch);
        $type = $kind === self::YesNoQuestion ? StepType::Choice : StepType::from($kind);
        abort_if($type === StepType::Branch, 422);

        return DB::transaction(function () use ($flow, $parentBranch, $position, $kind, $type): FlowStep {
            $this->makeRoom($flow, $parentBranch?->id, $position);

            $step = $flow->steps()->create([
                'parent_id' => $parentBranch?->id,
                'position' => $position,
                'type' => $type,
                'name' => $kind === self::YesNoQuestion ? __('Yes/no question') : $type->label(),
                'config' => $this->defaultConfig($type),
            ]);

            if ($type->speaks()) {
                $this->addClipItem($step, PromptSlot::Prompt, $step->name);
            }

            match (true) {
                $kind === self::YesNoQuestion => $this->addYesNoBranches($step),
                $type === StepType::Choice => $this->addChoiceBranches($step),
                $type === StepType::Check => $this->addCheckBranches($step),
                default => null,
            };

            return $step;
        });
    }

    public function addBranch(FlowStep $parent, ?string $name = null): FlowStep
    {
        abort_unless($parent->type === StepType::Choice, 422, 'Only a choice can have more branches.');

        $branches = $parent->children()->get();
        $usedDigits = [];

        foreach ($branches as $branch) {
            if ($branch->keypad_digit !== null) {
                $usedDigits[] = $branch->keypad_digit;
            }
        }
        $otherwise = $branches->firstWhere('is_otherwise', true);
        $position = $otherwise->position ?? $branches->count();
        $this->makeRoom($parent->flow, $parent->id, $position);

        return $parent->children()->create([
            'call_flow_id' => $parent->call_flow_id,
            'position' => $position,
            'type' => StepType::Branch,
            'name' => $name ?? __('Option :number', ['number' => $branches->count() + ($otherwise ? 0 : 1)]),
            'keypad_digit' => $this->nextFreeDigit($usedDigits),
            'config' => ['match' => BranchMatch::Keywords->value],
        ]);
    }

    /**
     * Move a step to a position in the top level (null) or in a branch.
     */
    public function moveStep(FlowStep $step, ?FlowStep $targetBranch, int $position): void
    {
        abort_if($step->type === StepType::Branch, 422);
        $this->ensureCanHoldSteps($targetBranch);

        if ($targetBranch !== null && ($targetBranch->call_flow_id !== $step->call_flow_id || $this->isWithin($targetBranch, $step))) {
            throw new InvalidArgumentException('A step cannot be moved inside itself.');
        }

        DB::transaction(function () use ($step, $targetBranch, $position): void {
            $previousParentId = $step->parent_id;
            $step->update(['parent_id' => $targetBranch?->id]);
            $this->reorder($step->call_flow_id, $targetBranch?->id, $step, $position);

            if ($previousParentId !== $targetBranch?->id) {
                $this->reorder($step->call_flow_id, $previousParentId);
            }
        });
    }

    /**
     * Reorder a branch among the other branches of its step.
     */
    public function moveBranch(FlowStep $branch, int $position): void
    {
        abort_unless($branch->type === StepType::Branch, 422);

        $this->reorder($branch->call_flow_id, $branch->parent_id, $branch, $position);
    }

    public function delete(FlowStep $step): void
    {
        DB::transaction(function () use ($step): void {
            $step->delete();
            $this->reorder($step->call_flow_id, $step->parent_id);
        });
    }

    /**
     * Add a new clip, named after the step, as the last piece of a slot.
     */
    public function addClipItem(FlowStep $step, PromptSlot $slot, string $name): AudioClip
    {
        $clip = $this->newClip($step->flow, $name, 'steps');

        $step->promptItems()->create([
            'slot' => $slot,
            'position' => (int) $step->promptItems()->where('slot', $slot)->max('position') + 1,
            'audio_clip_id' => $clip->id,
        ]);

        return $clip;
    }

    public function newClip(CallFlow $flow, string $name, string $group): AudioClip
    {
        $base = Str::slug($name, '_') ?: 'clip';
        $key = $base;
        $suffix = 2;

        while ($flow->clips()->where('key', $key)->exists()) {
            $key = $base.'_'.$suffix++;
        }

        return $flow->clips()->create(['key' => $key, 'group' => $group]);
    }

    /**
     * Whether $step is $ancestor or sits somewhere inside it.
     */
    private function isWithin(FlowStep $step, FlowStep $ancestor): bool
    {
        $current = $step;

        while ($current !== null) {
            if ($current->is($ancestor)) {
                return true;
            }

            $current = $current->parent;
        }

        return false;
    }

    private function ensureCanHoldSteps(?FlowStep $parentBranch): void
    {
        abort_if($parentBranch !== null && $parentBranch->type !== StepType::Branch, 422, 'Steps can only be added inside a branch.');
    }

    private function makeRoom(CallFlow $flow, ?int $parentId, int $position): void
    {
        $flow->steps()->where('parent_id', $parentId)->where('position', '>=', $position)->increment('position');
    }

    /**
     * Number the steps in one list 0, 1, 2…, optionally placing one step
     * at a given position first.
     */
    private function reorder(int $flowId, ?int $parentId, ?FlowStep $placed = null, int $position = 0): void
    {
        $siblings = FlowStep::query()
            ->where('call_flow_id', $flowId)
            ->where('parent_id', $parentId)
            ->when($placed, fn ($query) => $query->whereKeyNot($placed->id))
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($placed !== null) {
            $siblings->splice(max(0, min($position, $siblings->count())), 0, [$placed]);
        }

        foreach ($siblings->values() as $index => $sibling) {
            if ($sibling->position !== $index) {
                $sibling->update(['position' => $index]);
            }
        }
    }

    /**
     * @param  list<string>  $usedDigits
     */
    private function nextFreeDigit(array $usedDigits): ?string
    {
        foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'] as $digit) {
            if (! in_array($digit, $usedDigits, true)) {
                return $digit;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultConfig(StepType $type): array
    {
        return match ($type) {
            StepType::Check => ['condition' => CheckCondition::KnownCaller->value],
            StepType::AskNumber => ['variable' => 'amount', 'min' => 1, 'max' => 1000],
            StepType::AskSpeech => ['variable' => 'name'],
            default => [],
        };
    }

    private function addChoiceBranches(FlowStep $step): void
    {
        $this->addBranch($step, __('Option 1'));
        $this->addOtherwiseBranch($step);
    }

    private function addYesNoBranches(FlowStep $step): void
    {
        $step->children()->createMany([
            ['call_flow_id' => $step->call_flow_id, 'position' => 0, 'type' => StepType::Branch, 'name' => __('Yes'), 'keypad_digit' => '1', 'config' => ['match' => BranchMatch::Yes->value]],
            ['call_flow_id' => $step->call_flow_id, 'position' => 1, 'type' => StepType::Branch, 'name' => __('No'), 'keypad_digit' => '2', 'config' => ['match' => BranchMatch::No->value]],
        ]);

        $this->addOtherwiseBranch($step);
    }

    private function addCheckBranches(FlowStep $step): void
    {
        $step->children()->create(['call_flow_id' => $step->call_flow_id, 'position' => 0, 'type' => StepType::Branch, 'name' => __('Yes'), 'config' => []]);
        $this->addOtherwiseBranch($step);
    }

    /**
     * The branch taken when nothing else matches. Under a choice it is
     * empty, which means "not understood, ask again".
     */
    private function addOtherwiseBranch(FlowStep $step): void
    {
        $step->children()->create([
            'call_flow_id' => $step->call_flow_id,
            'position' => $step->children()->count(),
            'type' => StepType::Branch,
            'name' => __('Otherwise'),
            'is_otherwise' => true,
            'config' => [],
        ]);
    }
}
