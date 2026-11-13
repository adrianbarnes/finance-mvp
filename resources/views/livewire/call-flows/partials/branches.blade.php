{{-- The branches of a choice or check, side by side, each with its own steps. --}}
<div class="h-3 w-px bg-zinc-300 dark:bg-zinc-600"></div>

<div class="flex items-start gap-3" wire:sort="moveBranch" wire:sort:group="branches-{{ $step->id }}">
    @foreach ($this->stepsByParent->get($step->id, collect()) as $branch)
        <div
            wire:key="branch-{{ $branch->id }}"
            wire:sort:item="{{ $branch->id }}"
            class="relative flex flex-col items-center px-1 pt-3 before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-zinc-300 first:before:left-1/2 last:before:right-1/2 after:absolute after:left-1/2 after:top-0 after:h-3 after:w-px after:bg-zinc-300 dark:before:bg-zinc-600 dark:after:bg-zinc-600"
        >
            <div
                wire:click="selectStep({{ $branch->id }})"
                @class([
                    'flex max-w-48 cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1 text-xs shadow-sm',
                    'border-indigo-500 ring-2 ring-indigo-500/40' => $this->selectedStepId === $branch->id,
                    'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800' => $this->selectedStepId !== $branch->id && ! $branch->is_otherwise,
                    'border-dashed border-zinc-300 bg-zinc-100 text-zinc-500 dark:border-zinc-600 dark:bg-zinc-900' => $this->selectedStepId !== $branch->id && $branch->is_otherwise,
                ])
            >
                @unless ($branch->is_otherwise)
                    <span wire:sort:handle class="cursor-grab text-zinc-400" title="{{ __('Drag to reorder') }}" x-on:click.stop>
                        <flux:icon.bars-2 variant="micro" />
                    </span>
                @endunless
                @if ($branch->keypad_digit !== null)
                    <span class="flex size-4 shrink-0 items-center justify-center rounded bg-zinc-800 font-mono text-[10px] text-white dark:bg-white dark:text-zinc-900">{{ $branch->keypad_digit }}</span>
                @endif
                <span class="truncate font-medium">{{ $branch->name }}</span>
            </div>

            @include('livewire.call-flows.partials.step-list', [
                'parentId' => $branch->id,
                'emptyNote' => $branch->is_otherwise && $step->type === \App\Enums\StepType::Choice ? __('not understood → ask again') : __('then continues below'),
            ])
        </div>
    @endforeach
</div>
