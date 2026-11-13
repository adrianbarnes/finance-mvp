@use('App\Enums\PromptSlot')
@use('App\Enums\StepType')

@php
    $parentType = $step->parent?->type;
    $isChoiceBranch = $step->type === StepType::Branch && $parentType === StepType::Choice && ! $step->is_otherwise;
    $status = $this->audioStatuses[$step->id] ?? ['state' => 'silent', 'language' => null];
@endphp

<aside class="sticky top-4 max-h-[calc(100vh-2rem)] w-96 shrink-0 space-y-6 overflow-y-auto rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800" wire:key="panel-{{ $step->id }}">
    <div class="flex items-start justify-between gap-2">
        <div class="flex items-center gap-2">
            <flux:icon :icon="$step->type->icon()" variant="mini" class="text-zinc-500" />
            <div>
                <flux:heading>{{ $step->type->label() }}</flux:heading>
                <flux:text class="text-xs">{{ $step->type->description() }}</flux:text>
            </div>
        </div>
        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="closePanel" :aria-label="__('Close')" />
    </div>

    <form wire:submit="saveStep" class="space-y-4">
        <flux:input wire:model="stepName" :label="__('Name')" :description="__('Shown in the builder only.')" />

        @if ($isChoiceBranch)
            <flux:input wire:model="keypadDigit" :label="__('Key')" maxlength="1" class="w-20" />

            <flux:select wire:model.live="branchMatch" :label="__('Picked by saying')">
                @foreach ($branchMatches as $match)
                    <flux:select.option :value="$match->value">{{ $match->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($branchMatch === 'keywords')
                <flux:textarea
                    wire:model="keywords"
                    rows="2"
                    :label="__('Words in :language', ['language' => $this->language->name])"
                    :description="__('Separate with commas. Switch the audio language above to edit another language\'s words.')"
                />
            @endif
        @elseif ($step->type === StepType::Branch)
            <flux:text class="text-sm">
                {{ $step->is_otherwise
                    ? ($parentType === StepType::Choice ? __('Taken when the answer matches no option. Leave it empty to say "not understood" and ask again.') : __('Taken when the check does not hold.'))
                    : __('Taken when the check holds.') }}
            </flux:text>
        @endif

        @if ($step->type === StepType::Check)
            <flux:select wire:model="condition" :label="__('Check whether')">
                @foreach ($conditions as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        @if ($step->type === StepType::Do)
            <flux:select wire:model="action" :label="__('Action')">
                <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                @foreach ($actions as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        @if (in_array($step->type, [StepType::AskNumber, StepType::AskSpeech], true))
            <flux:input wire:model="variable" :label="__('Save the answer as')" :description="__('Other steps can speak it as a value, e.g. Answer: amount.')" />
        @endif

        @if ($step->type === StepType::AskNumber)
            <flux:select wire:model="unit" :label="__('The answer is')">
                @foreach ($units as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="min" type="number" :label="__('Smallest')" />
                <flux:input wire:model="max" type="number" :label="__('Largest')" />
            </div>
        @endif

        @if ($step->type === StepType::GoTo)
            <flux:select wire:model="goToStepId" :label="__('Go to')">
                <flux:select.option value="">{{ __('Choose a step…') }}</flux:select.option>
                @foreach ($this->steps->where('type', '!=', StepType::Branch)->where('id', '!=', $step->id) as $target)
                    <flux:select.option :value="$target->id">{{ $target->name }} ({{ $target->type->label() }})</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:button type="submit" variant="primary" size="sm">{{ __('Save step') }}</flux:button>
    </form>

    @foreach (PromptSlot::forType($step->type) as $slot)
        <div class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-700" wire:key="slot-{{ $step->id }}-{{ $slot->value }}">
            <div class="flex items-center justify-between gap-2">
                <flux:heading size="sm">{{ $slot->label() }} · {{ $this->language->name }}</flux:heading>
                @if ($slot === PromptSlot::Prompt)
                    @include('livewire.call-flows.partials.audio-badge', ['step' => $step])
                @endif
            </div>

            @if ($slot === PromptSlot::Prompt && $status['state'] === 'fallback')
                <flux:text class="text-xs">{{ __('Some pieces have no :language recording, so callers in :language hear this step in :fallback.', ['language' => $this->language->name, 'fallback' => $status['language']->name]) }}</flux:text>
            @endif

            @if ($slot === PromptSlot::Prompt && $step->type === StepType::ChooseLanguage)
                <flux:text class="text-xs">{{ __('Each language offers itself with its own recording, one after another. A language with wording but no recording yet is read aloud by the browser phone only.') }}</flux:text>
            @endif

            @php($pieces = $step->promptItemsFor($slot))

            @forelse ($pieces as $piece)
                <div class="space-y-2 rounded-md border border-zinc-200 p-2 dark:border-zinc-700" wire:key="piece-{{ $piece->id }}">
                    <div class="flex items-center justify-between gap-2">
                        @if ($piece->clip)
                            <span class="truncate font-mono text-xs">{{ $piece->clip->key }}</span>
                        @else
                            <flux:badge size="sm" color="sky" icon="variable">{{ \App\Enums\Placeholder::describe((string) $piece->placeholder) }}</flux:badge>
                        @endif

                        <div class="flex shrink-0">
                            <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="movePiece({{ $piece->id }}, -1)" :disabled="$loop->first" :aria-label="__('Move up')" />
                            <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="movePiece({{ $piece->id }}, 1)" :disabled="$loop->last" :aria-label="__('Move down')" />
                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removePiece({{ $piece->id }})" :aria-label="__('Remove piece')" />
                        </div>
                    </div>

                    @if ($piece->clip)
                        @include('livewire.call-flows.partials.clip-recording', ['clip' => $piece->clip])
                    @else
                        <flux:text class="text-xs">{{ __('Spoken at call time.') }}</flux:text>
                    @endif
                </div>
            @empty
                <flux:text class="text-xs">{{ __('Nothing yet.') }}</flux:text>
            @endforelse

            <flux:dropdown position="bottom" align="start">
                <flux:button size="xs" icon="plus">{{ __('Add piece') }}</flux:button>

                <flux:menu class="max-h-80 overflow-y-auto">
                    <flux:menu.item icon="arrow-up-tray" wire:click="addPiece('{{ $slot->value }}', 'new')">{{ __('New recording') }}</flux:menu.item>

                    <flux:menu.separator />
                    <flux:menu.heading>{{ __('Value spoken at call time') }}</flux:menu.heading>
                    @foreach ($placeholders as $placeholder)
                        <flux:menu.item wire:click="addPiece('{{ $slot->value }}', 'value:{{ $placeholder->value }}')">{{ $placeholder->label() }}</flux:menu.item>
                    @endforeach
                    @foreach ($this->answerNames as $answer)
                        <flux:menu.item wire:click="addPiece('{{ $slot->value }}', 'answer:{{ $answer }}')">{{ __('Answer: :name', ['name' => $answer]) }}</flux:menu.item>
                    @endforeach

                    <flux:menu.separator />
                    <flux:menu.heading>{{ __('Existing clip') }}</flux:menu.heading>
                    @foreach ($this->clips as $clip)
                        <flux:menu.item wire:click="addPiece('{{ $slot->value }}', 'clip:{{ $clip->id }}')">{{ $clip->key }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
        </div>
    @endforeach

    <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
        <flux:button
            size="sm"
            variant="danger"
            icon="trash"
            wire:click="deleteStep({{ $step->id }})"
            wire:confirm="{{ $step->type->hasBranches() || $step->type === StepType::Branch ? __('Delete this step and everything inside it?') : __('Delete this step?') }}"
        >
            {{ __('Delete step') }}
        </flux:button>
    </div>
</aside>
