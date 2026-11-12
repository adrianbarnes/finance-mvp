<section class="w-full space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Voice prompts') }}</flux:heading>
            <flux:subheading>{{ __('Wording and audio the assistant plays. Generate audio with Abena AI (sparkle button) or upload a recording. Without audio, the browser phone reads the text aloud.') }}</flux:subheading>
        </div>

        <flux:select wire:model.live="language" class="max-w-40">
            @foreach ($languages as $option)
                <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Function') }}</flux:table.column>
            <flux:table.column>{{ __('Step') }}</flux:table.column>
            <flux:table.column>{{ __('Text') }}</flux:table.column>
            <flux:table.column>{{ __('Recording') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->prompts as $prompt)
                <flux:table.row :key="$prompt->id">
                    <flux:table.cell><flux:badge size="sm">{{ str_replace('_', ' ', $prompt->group()) }}</flux:badge></flux:table.cell>
                    <flux:table.cell class="font-mono text-xs">{{ $prompt->key }}</flux:table.cell>
                    <flux:table.cell class="max-w-xl whitespace-normal">{{ $prompt->text }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($prompt->audio_path)
                            <audio controls preload="none" src="{{ $prompt->audioUrl() }}" class="h-8"></audio>
                        @else
                            <flux:badge size="sm" color="amber">{{ __('Text only') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="sparkles" wire:click="generate({{ $prompt->id }})" wire:loading.attr="disabled" wire:target="generate({{ $prompt->id }})" :tooltip="__('Generate with Abena AI')" />
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $prompt->id }})" />
                        @if ($prompt->audio_path)
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeRecording({{ $prompt->id }})" wire:confirm="{{ __('Remove this recording?') }}" />
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="edit-prompt" class="md:w-[32rem]">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ __('Edit prompt') }}</flux:heading>

            <flux:textarea wire:model="text" :label="__('Text')" rows="4" required />

            <flux:field>
                <flux:label>{{ __('Recording (WAV, MP3, M4A, OGG or WebM)') }}</flux:label>
                <input type="file" wire:model="recording" accept="audio/*" class="block w-full text-sm" />
                <div wire:loading wire:target="recording" class="text-sm text-zinc-500">{{ __('Uploading...') }}</div>
                <flux:error name="recording" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
