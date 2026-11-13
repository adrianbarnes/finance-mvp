<div class="space-y-4">
    <flux:text>{{ __('Every clip in this draft, with its :language recording. A clip can be used by many steps; upload it once here or from any step that uses it.', ['language' => $this->language->name]) }}</flux:text>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($this->clips as $clip)
            <flux:card class="space-y-2 p-3" wire:key="clip-{{ $clip->id }}">
                <div class="flex items-center justify-between gap-2">
                    <span class="truncate font-mono text-sm">{{ $clip->key }}</span>
                    <div class="flex shrink-0 gap-1">
                        <flux:badge size="sm">{{ $clip->group }}</flux:badge>
                        <flux:badge size="sm" :color="$clip->prompt_items_count > 0 ? 'zinc' : 'amber'">
                            {{ trans_choice(':count step|:count steps', $clip->prompt_items_count) }}
                        </flux:badge>
                    </div>
                </div>

                @include('livewire.call-flows.partials.clip-recording', ['clip' => $clip])
            </flux:card>
        @endforeach
    </div>
</div>
