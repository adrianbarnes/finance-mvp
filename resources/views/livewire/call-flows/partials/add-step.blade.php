{{-- Opens the shared "add a step" menu for this place in the flow. --}}
<div wire:sort:ignore>
    <button
        type="button"
        x-on:click="$dispatch('open-add-step', { branchId: {{ $branchId ?? 'null' }}, position: {{ $position }}, anchor: $el })"
        class="flex size-6 items-center justify-center rounded-full border border-zinc-300 bg-white text-zinc-500 shadow-sm hover:border-zinc-500 hover:text-zinc-800 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:text-white"
        title="{{ __('Add a step here') }}"
        aria-label="{{ __('Add a step here') }}"
    >
        <flux:icon.plus variant="micro" />
    </button>
</div>
