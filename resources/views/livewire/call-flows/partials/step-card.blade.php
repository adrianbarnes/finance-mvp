@php
    $selected = $this->selectedStepId === $step->id;
    $detail = match ($step->type) {
        \App\Enums\StepType::Check => \App\Enums\CheckCondition::tryFrom((string) $step->configValue('condition'))?->label(),
        \App\Enums\StepType::Do => \App\Enums\StepAction::tryFrom((string) $step->configValue('action'))?->label(),
        \App\Enums\StepType::AskNumber => __('Saves ":name", :min–:max', ['name' => $step->configValue('variable'), 'min' => $step->configValue('min'), 'max' => $step->configValue('max')]),
        \App\Enums\StepType::AskSpeech => __('Saves ":name"', ['name' => $step->configValue('variable')]),
        \App\Enums\StepType::GoTo => '→ '.($step->goToStep?->name ?? __('choose a step')),
        default => null,
    };
@endphp

<div
    wire:click="selectStep({{ $step->id }})"
    @class([
        'w-60 cursor-pointer rounded-lg border bg-white p-3 text-start shadow-sm transition hover:shadow dark:bg-zinc-800',
        'border-indigo-500 ring-2 ring-indigo-500/40' => $selected,
        'border-zinc-200 dark:border-zinc-700' => ! $selected,
        'border-dashed' => $step->type === \App\Enums\StepType::GoTo,
    ])
>
    <div class="flex items-center gap-2 text-zinc-500 dark:text-zinc-400">
        <span wire:sort:handle class="cursor-grab" title="{{ __('Drag to move') }}" x-on:click.stop>
            <flux:icon.bars-3 variant="micro" />
        </span>
        <flux:icon :icon="$step->type->icon()" variant="micro" />
        <span class="text-[11px] font-medium uppercase tracking-wide">{{ $step->type->label() }}</span>
    </div>

    <div class="mt-1 truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $step->name }}</div>

    @if ($detail)
        <div class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $detail }}</div>
    @endif

    <div class="mt-2 flex items-center justify-between gap-2">
        @include('livewire.call-flows.partials.audio-badge', ['step' => $step])

        <div class="flex items-center">
            @if ($step->type === \App\Enums\StepType::Choice)
                <flux:button size="xs" variant="ghost" icon="plus" wire:click.stop="addBranch({{ $step->id }})">{{ __('Option') }}</flux:button>
            @endif
            @if ($step->type->hasBranches())
                <flux:button
                    size="xs"
                    variant="ghost"
                    :icon="in_array($step->id, $collapsedStepIds, true) ? 'chevron-down' : 'chevron-up'"
                    wire:click.stop="toggleBranches({{ $step->id }})"
                    :aria-label="in_array($step->id, $collapsedStepIds, true) ? __('Show branches') : __('Hide branches')"
                />
            @endif
        </div>
    </div>
</div>
