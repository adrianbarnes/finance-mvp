<section class="w-full space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('IVR flow') }}</flux:heading>
            <flux:subheading>
                {{ __('Editing the draft (version :draft). Calls use version :live, published :when. Add a step with +, drag a step by its handle to move it, and click a step to set it up and upload its audio.', [
                    'draft' => $this->flow->version,
                    'live' => $this->liveFlow->version,
                    'when' => $this->liveFlow->published_at?->diffForHumans() ?? '—',
                ]) }}
            </flux:subheading>
        </div>

        <div class="flex items-end gap-3">
            <flux:select wire:model.live="languageCode" :label="__('Audio language')" class="w-44">
                @foreach ($this->languages as $option)
                    <flux:select.option :value="$option->code">{{ $option->name }}{{ $option->is_primary ? ' ('.__('primary').')' : '' }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:button
                wire:click="discardDraft"
                wire:confirm="{{ __('Throw away every change in the draft and start again from the live version?') }}"
            >{{ __('Discard draft') }}</flux:button>

            <flux:button
                variant="primary"
                icon="rocket-launch"
                wire:click="publish"
                wire:confirm="{{ __('Publish the draft? New calls will use it straight away.') }}"
            >{{ __('Publish') }}</flux:button>
        </div>
    </div>

    @if ($publishProblems !== [])
        <flux:callout icon="exclamation-triangle" variant="danger">
            <flux:callout.heading>{{ __('The draft was not published') }}</flux:callout.heading>
            <flux:callout.text>
                <ul class="list-inside list-disc">
                    @foreach ($publishProblems as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @else
        <flux:callout icon="information-circle" variant="secondary">
            <flux:callout.text>{{ __('Changes here, including uploads, only reach callers when you publish. Turn on "Test draft" on the call console to try the draft first.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex gap-1 border-b border-zinc-200 dark:border-zinc-700">
        @foreach (['flow' => __('Flowchart'), 'clips' => __('Clip library'), 'settings' => __('Settings'), 'versions' => __('Versions')] as $value => $label)
            <button
                type="button"
                wire:click="$set('tab', '{{ $value }}')"
                @class([
                    '-mb-px border-b-2 px-3 py-2 text-sm font-medium',
                    'border-zinc-800 text-zinc-900 dark:border-white dark:text-white' => $tab === $value,
                    'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' => $tab !== $value,
                ])
            >{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'flow')
        <div class="flex items-start gap-4">
            <div
                class="relative min-w-0 flex-1"
                x-data="{
                    zoom: 0.85,
                    init() {
                        try { this.zoom = parseFloat(localStorage.getItem('ivr-flow-zoom')) || 0.85 } catch (error) {}
                        this.$nextTick(() => this.center())
                    },
                    setZoom(value) {
                        this.zoom = Math.min(1.25, Math.max(0.4, Math.round(value * 100) / 100))
                        try { localStorage.setItem('ivr-flow-zoom', this.zoom) } catch (error) {}
                    },
                    center() {
                        const canvas = this.$refs.canvas
                        canvas.scrollLeft = (canvas.scrollWidth - canvas.clientWidth) / 2
                    },
                }"
            >
                <div class="absolute end-3 top-3 z-10 flex items-center gap-1 rounded-lg border border-zinc-200 bg-white/90 p-1 shadow-sm backdrop-blur dark:border-zinc-700 dark:bg-zinc-800/90">
                    <flux:button size="xs" variant="ghost" icon="minus" x-on:click="setZoom(zoom - 0.1)" :aria-label="__('Zoom out')" />
                    <span class="w-10 text-center text-xs tabular-nums text-zinc-600 dark:text-zinc-300" x-text="Math.round(zoom * 100) + '%'"></span>
                    <flux:button size="xs" variant="ghost" icon="plus" x-on:click="setZoom(zoom + 0.1)" :aria-label="__('Zoom in')" />
                    <flux:button size="xs" variant="ghost" x-on:click="center()">{{ __('Centre') }}</flux:button>
                    <flux:separator vertical class="my-1" />
                    <flux:button size="xs" variant="ghost" wire:click="collapseAll">{{ __('Collapse all') }}</flux:button>
                    <flux:button size="xs" variant="ghost" wire:click="expandAll">{{ __('Expand all') }}</flux:button>
                </div>

                <div
                    x-ref="canvas"
                    class="h-[calc(100vh-15rem)] min-h-96 overflow-auto rounded-lg border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 [background-image:radial-gradient(circle,rgb(161_161_170/0.35)_1px,transparent_1px)] [background-size:16px_16px]"
                >
                    <div class="mx-auto w-max px-10 pb-16 pt-14" x-bind:style="`zoom: ${zoom}`">
                        <div class="mx-auto mb-1 w-max rounded-full bg-zinc-800 px-3 py-1 text-xs font-medium text-white dark:bg-white dark:text-zinc-900">{{ __('Call starts') }}</div>
                        @include('livewire.call-flows.partials.step-list', ['parentId' => null])
                        <div class="mx-auto mt-1 w-max rounded-full bg-zinc-300 px-3 py-1 text-xs font-medium text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">{{ __('Call ends') }}</div>
                    </div>
                </div>
            </div>

            @include('livewire.call-flows.partials.add-step-menu')

            @if ($this->selectedStep)
                @include('livewire.call-flows.partials.panel', ['step' => $this->selectedStep])
            @endif
        </div>
    @elseif ($tab === 'clips')
        @include('livewire.call-flows.partials.clips')
    @elseif ($tab === 'versions')
        @include('livewire.call-flows.partials.versions')
    @else
        @include('livewire.call-flows.partials.settings')
    @endif
</section>
