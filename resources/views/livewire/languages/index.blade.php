<section class="w-full space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Languages') }}</flux:heading>
            <flux:subheading>{{ __('Languages callers can choose. Drag to reorder: the order is the order they are offered in, and a language\'s place is the key that picks it. Steps without audio in a caller\'s language play the primary language.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('Add language') }}</flux:button>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column class="w-10"></flux:table.column>
            <flux:table.column>{{ __('Key') }}</flux:table.column>
            <flux:table.column>{{ __('Language') }}</flux:table.column>
            <flux:table.column>{{ __('Speech') }}</flux:table.column>
            <flux:table.column>{{ __('Picked by saying') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows wire:sort="sort">
            @php($keypadNumber = 0)
            @foreach ($this->languages as $language)
                <flux:table.row :key="$language->id" wire:sort:item="{{ $language->id }}">
                    <flux:table.cell>
                        <div wire:sort:handle class="cursor-grab text-zinc-400" title="{{ __('Drag to reorder') }}">
                            <flux:icon.bars-3 variant="mini" />
                        </div>
                    </flux:table.cell>
                    <flux:table.cell class="font-mono">
                        @if ($language->enabled)
                            {{ ++$keypadNumber }}
                        @else
                            <span class="text-zinc-400">—</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ $language->name }}</span>
                            <span class="font-mono text-xs text-zinc-500">{{ $language->code }}</span>
                            @if ($language->is_primary)
                                <flux:badge size="sm" color="indigo">{{ __('Primary') }}</flux:badge>
                            @endif
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm">{{ $language->speech_recognition->label() }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="max-w-64 truncate text-zinc-500">
                        {{ implode(', ', $language->selection_words) ?: '—' }}
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($language->enabled)
                            <flux:badge size="sm" color="green">{{ __('On') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">{{ __('Off') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:dropdown position="bottom" align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Actions for :language', ['language' => $language->name])" />

                            <flux:menu>
                                <flux:menu.item icon="pencil-square" wire:click="edit({{ $language->id }})">{{ __('Edit') }}</flux:menu.item>
                                <flux:menu.item :icon="$language->enabled ? 'pause' : 'play'" wire:click="toggleEnabled({{ $language->id }})" :disabled="$language->is_primary">
                                    {{ $language->enabled ? __('Switch off') : __('Switch on') }}
                                </flux:menu.item>
                                <flux:menu.item icon="star" wire:click="makePrimary({{ $language->id }})" :disabled="$language->is_primary">{{ __('Make primary') }}</flux:menu.item>
                                <flux:menu.separator />
                                <flux:menu.item
                                    icon="trash"
                                    variant="danger"
                                    wire:click="delete({{ $language->id }})"
                                    wire:confirm="{{ __('Delete :language? Callers who saved it will be asked for a language again. You can restore it later.', ['language' => $language->name]) }}"
                                    :disabled="$language->is_primary"
                                >
                                    {{ __('Delete') }}
                                </flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    @if ($this->deletedLanguages->isNotEmpty())
        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Deleted languages') }}</flux:heading>

            <flux:table>
                <flux:table.rows>
                    @foreach ($this->deletedLanguages as $language)
                        <flux:table.row :key="'deleted-'.$language->id">
                            <flux:table.cell>
                                <span class="font-medium">{{ $language->name }}</span>
                                <span class="ms-2 font-mono text-xs text-zinc-500">{{ $language->code }}</span>
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-500">{{ __('Deleted :date', ['date' => $language->deleted_at->diffForHumans()]) }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button size="sm" icon="arrow-uturn-left" wire:click="restore({{ $language->id }})">{{ __('Restore') }}</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:modal name="language-form" class="md:w-[32rem]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingLanguageId ? __('Edit language') : __('Add language') }}</flux:heading>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="name" :label="__('Name')" placeholder="Fante" required />
                <flux:input
                    wire:model="code"
                    :label="__('Code (ISO 639-3)')"
                    placeholder="fat"
                    :disabled="$editingLanguageId !== null"
                    :description="$editingLanguageId ? __('The code cannot be changed.') : null"
                />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model.live="speechRecognition" :label="__('Speech recognition')">
                    @foreach ($recognitionOptions as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($speechRecognition === 'khaya')
                    <flux:input wire:model="khayaCode" :label="__('Khaya language code')" placeholder="twi" />
                @endif
            </div>

            <flux:textarea wire:model="selectionWords" rows="2" :label="__('Words that pick this language')" :description="__('On the language choice, e.g. its \'yes\' and its name. Separate with commas.')" />
            <flux:textarea wire:model="yesWords" rows="2" :label="__('Yes words')" :description="__('Start a word with = to match only the whole answer, e.g. =no.')" />
            <flux:textarea wire:model="noWords" rows="2" :label="__('No words')" />
            <flux:textarea wire:model="numberWords" rows="4" :label="__('Number words')" :description="__('One per line, e.g. baako = 1. English numbers are always understood.')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
