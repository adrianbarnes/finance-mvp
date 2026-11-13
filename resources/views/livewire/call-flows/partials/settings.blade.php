<div class="max-w-xl space-y-6">
    <form wire:submit="saveSettings" class="space-y-4">
        <flux:input wire:model="maxNoInputReplays" type="number" min="0" max="5" :label="__('Replays when the caller says nothing')" :description="__('After these, the caller is thanked and the call ends.')" />
        <flux:input wire:model="maxMisunderstoodAttempts" type="number" min="1" max="10" :label="__('Misunderstood answers allowed in a row')" :description="__('On the last one, the caller is told the call has to end.')" />
        <flux:button type="submit" variant="primary" size="sm">{{ __('Save settings') }}</flux:button>
    </form>

    <div class="space-y-2">
        <flux:heading size="sm">{{ __('Clips played outside any step') }}</flux:heading>
        <flux:text class="text-sm">{{ __('Upload these in the clip library.') }}</flux:text>
        <ul class="list-inside list-disc text-sm text-zinc-600 dark:text-zinc-300">
            <li><span class="font-mono">{{ $this->flow->setting('not_understood_clip') }}</span> — {{ __('before asking again') }}</li>
            <li><span class="font-mono">{{ $this->flow->setting('too_many_attempts_clip') }}</span> — {{ __('before ending after too many misunderstood answers') }}</li>
            <li><span class="font-mono">{{ $this->flow->setting('speech_unavailable_clip') }}</span> — {{ __('when speech cannot be recognised, asking for the keypad') }}</li>
            <li><span class="font-mono">{{ $this->flow->setting('no_input_goodbye_clip') }}</span> — {{ __('before ending when the caller says nothing') }}</li>
        </ul>
    </div>
</div>
