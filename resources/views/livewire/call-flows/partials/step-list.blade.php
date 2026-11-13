{{-- A vertical list of steps: the top level (parentId null) or the steps inside a branch. --}}
@php($listSteps = $this->stepsByParent->get($parentId ?? 'root', collect()))

<div class="flex flex-col items-center">
    <div class="h-3 w-px bg-zinc-300 dark:bg-zinc-600"></div>
    @include('livewire.call-flows.partials.add-step', ['branchId' => $parentId, 'position' => 0])

    <div
        class="flex min-h-2 min-w-40 flex-col items-center"
        wire:sort="moveStep"
        wire:sort:group="steps"
        wire:sort:group-id="{{ $parentId ?? 'root' }}"
    >
        @foreach ($listSteps as $step)
            <div wire:key="step-{{ $step->id }}" wire:sort:item="{{ $step->id }}" class="flex flex-col items-center">
                <div class="h-3 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                @include('livewire.call-flows.partials.step-card', ['step' => $step])

                @if ($step->type->hasBranches())
                    @if (in_array($step->id, $collapsedStepIds, true))
                        <div class="h-3 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                        <button type="button" wire:click="toggleBranches({{ $step->id }})" class="rounded-full border border-dashed border-zinc-300 bg-white px-3 py-1 text-xs text-zinc-500 hover:text-zinc-800 dark:border-zinc-600 dark:bg-zinc-800 dark:hover:text-white">
                            {{ trans_choice('Show :count branch|Show :count branches', $this->stepsByParent->get($step->id, collect())->count()) }}
                        </button>
                    @else
                        @include('livewire.call-flows.partials.branches', ['step' => $step])
                    @endif
                @endif

                <div class="h-3 w-px bg-zinc-300 dark:bg-zinc-600"></div>
                @include('livewire.call-flows.partials.add-step', ['branchId' => $parentId, 'position' => $loop->index + 1])
            </div>
        @endforeach
    </div>

    @if ($parentId !== null && $listSteps->isEmpty())
        <div class="mt-1 text-[11px] text-zinc-400">{{ $emptyNote ?? __('then continues below') }}</div>
    @endif
</div>
