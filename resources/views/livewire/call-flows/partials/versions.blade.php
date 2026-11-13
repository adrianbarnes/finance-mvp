@php($archivedCount = $this->versions->where('status', \App\Enums\FlowStatus::Archived)->count())

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:text class="max-w-2xl">
            {{ __('Every published version is kept. Copy an earlier one into the draft to check and test it before publishing, or publish it again straight away. Deleting archived versions also removes audio files no other version uses; past calls keep their transcripts and recordings.') }}
        </flux:text>

        <flux:button
            variant="danger"
            icon="trash"
            wire:click="purgeArchived"
            wire:confirm="{{ __('Delete all :count archived versions? This cannot be undone.', ['count' => $archivedCount]) }}"
            :disabled="$archivedCount === 0"
        >{{ __('Purge archived versions') }}</flux:button>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Version') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Published') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Calls') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->versions as $version)
                <flux:table.row :key="'version-'.$version->id">
                    <flux:table.cell class="font-medium">v{{ $version->version }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="match ($version->status) { \App\Enums\FlowStatus::Published => 'green', \App\Enums\FlowStatus::Draft => 'sky', default => 'zinc' }">
                            {{ $version->status === \App\Enums\FlowStatus::Published ? __('Live') : $version->status->label() }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="text-zinc-500">{{ $version->published_at?->format('j M Y, H:i') ?? '—' }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $version->call_sessions_count }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            @unless ($version->status === \App\Enums\FlowStatus::Draft)
                                <flux:button
                                    size="sm"
                                    icon="document-duplicate"
                                    wire:click="restoreVersion({{ $version->id }})"
                                    wire:confirm="{{ __('Replace the current draft with a copy of version :version? Changes in the draft will be lost.', ['version' => $version->version]) }}"
                                >{{ __('Copy into draft') }}</flux:button>
                            @endunless

                            @if ($version->status === \App\Enums\FlowStatus::Archived)
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="arrow-uturn-left"
                                    wire:click="republishVersion({{ $version->id }})"
                                    wire:confirm="{{ __('Make version :version live again now? New calls will use it straight away.', ['version' => $version->version]) }}"
                                >{{ __('Publish again') }}</flux:button>
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="trash"
                                    wire:click="deleteVersion({{ $version->id }})"
                                    wire:confirm="{{ __('Delete version :version? This cannot be undone.', ['version' => $version->version]) }}"
                                    :aria-label="__('Delete version :version', ['version' => $version->version])"
                                />
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
