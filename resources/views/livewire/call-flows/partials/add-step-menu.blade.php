{{-- One menu of step types, shared by every + in the flowchart. --}}
<div
    x-data="{ open: false, branchId: null, position: 0, top: 0, left: 0 }"
    x-on:open-add-step.window="
        const box = $event.detail.anchor.getBoundingClientRect();
        branchId = $event.detail.branchId;
        position = $event.detail.position;
        top = box.bottom + 6;
        left = box.left + box.width / 2;
        open = true;
    "
    x-on:keydown.escape.window="open = false"
    x-on:scroll.window="open = false"
>
    <div
        x-show="open"
        x-cloak
        x-transition.opacity
        x-on:click.outside="open = false"
        x-bind:style="`top: ${top}px; left: ${left}px`"
        class="fixed z-50 w-60 -translate-x-1/2 rounded-lg border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800"
        role="menu"
    >
        @foreach ($addableKinds as $kind)
            <button
                type="button"
                role="menuitem"
                x-on:click="open = false; $wire.addStep(branchId, position, '{{ $kind['value'] }}')"
                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm text-zinc-800 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-700"
            >
                <flux:icon :icon="$kind['icon']" variant="mini" class="text-zinc-400" />
                {{ $kind['label'] }}
            </button>
        @endforeach
    </div>
</div>
