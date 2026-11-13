{{-- A clip's recording in the selected language: listen, upload, remove, and its wording. --}}
@php($recording = $clip->recordingFor($this->language))

<div class="space-y-2">
    @if ($recording?->audio_path)
        <div class="flex items-center gap-2">
            <audio controls preload="none" src="{{ $recording->audioUrl() }}" class="h-8 min-w-0 flex-1"></audio>
            <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeRecording({{ $clip->id }})" wire:confirm="{{ __('Remove the :language recording?', ['language' => $this->language->name]) }}" :aria-label="__('Remove recording')" />
        </div>
        @if ($recording->original_filename)
            <div class="truncate text-[11px] text-zinc-400">{{ $recording->original_filename }}{{ $recording->duration_ms ? ' · '.number_format($recording->duration_ms / 1000, 1).'s' : '' }}</div>
        @endif
    @else
        <div class="text-xs text-red-600 dark:text-red-400">{{ __('No :language recording.', ['language' => $this->language->name]) }}</div>
    @endif

    <label class="flex cursor-pointer items-center gap-2 text-xs font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">
        <flux:icon.arrow-up-tray variant="micro" />
        <span wire:loading.remove wire:target="uploads.{{ $clip->id }}">{{ $recording?->audio_path ? __('Replace recording') : __('Upload recording') }}</span>
        <span wire:loading wire:target="uploads.{{ $clip->id }}">{{ __('Uploading…') }}</span>
        <input type="file" accept="audio/*" class="sr-only" wire:model="uploads.{{ $clip->id }}" />
    </label>
    <flux:error name="uploads.{{ $clip->id }}" />

    <flux:textarea
        wire:model.blur="recordingTexts.{{ $clip->id }}"
        rows="2"
        :placeholder="__('What the recording says (optional)')"
        class="text-xs"
    />
</div>
