<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Call console') }}</flux:heading>
            <flux:subheading>{{ __('Call the voice assistant from the browser. Speak naturally — speaking or pressing a key while it talks interrupts it. Use a headset to stop the assistant hearing itself.') }}</flux:subheading>
        </div>

        <flux:switch
            wire:model.live="remembersLanguage"
            :label="__('Remember caller languages')"
            :description="__('Off: every call starts with the language prompt and nothing is saved.')"
            align="left"
            class="max-w-xs"
        />
    </div>

    <div
        wire:ignore
        x-data="callConsole(@js([
            'csrfToken' => csrf_token(),
            'noInputSeconds' => (int) config('conversation.no_input_timeout_seconds'),
            'quota' => $quota,
            'defaultCallerNumber' => $this->customers->first()?->phone_number,
            'routes' => [
                'start' => route('calls.store'),
                'utterance' => route('calls.utterances.store', ['call' => '__CALL__']),
                'input' => route('calls.inputs.store', ['call' => '__CALL__']),
                'hangUp' => route('calls.destroy', ['call' => '__CALL__']),
            ],
        ]))"
        class="grid gap-6 lg:grid-cols-[22rem_1fr]"
    >
        <flux:card class="space-y-5">
            <div class="space-y-2">
                <flux:input x-model="callerNumber" :label="__('Caller number')" list="customer-numbers" placeholder="0244123456" x-bind:disabled="inCall" />
                <datalist id="customer-numbers">
                    @foreach ($this->customers as $customer)
                        <option value="{{ $customer->phone_number }}">{{ $customer->full_name ?? __('(no name)') }} — {{ $customer->preferred_language?->label() ?? __('no language') }}</option>
                    @endforeach
                </datalist>
                <flux:text class="text-xs">{{ __('Use any other number to test sign-up of an unknown caller.') }}</flux:text>
            </div>

            <div class="flex gap-2">
                <flux:button variant="primary" icon="phone" class="flex-1" x-on:click="startCall()" x-show="!inCall" x-bind:disabled="status === 'connecting' || callerNumber.trim() === ''">
                    {{ __('Call') }}
                </flux:button>
                <flux:button variant="danger" icon="phone-x-mark" class="flex-1" x-on:click="hangUp()" x-show="inCall" x-cloak>
                    {{ __('Hang up') }}
                </flux:button>
            </div>

            <div class="space-y-1 text-sm">
                <div class="flex justify-between"><span class="text-zinc-500">{{ __('Status') }}</span><span x-text="busy ? 'thinking…' : (speaking ? 'hearing you…' : status)"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">{{ __('Dialogue state') }}</span><span class="font-mono text-xs" x-text="callState ?? '—'"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">{{ __('Language') }}</span><span class="font-mono text-xs" x-text="language ? language + (clientRecognition ? ' · browser ASR' : ' · Khaya ASR') : '—'"></span></div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-zinc-500">{{ __('Microphone') }}</span>
                    <div class="h-2 w-32 overflow-hidden rounded bg-zinc-200 dark:bg-zinc-700">
                        <div class="h-full bg-green-500 transition-all duration-75" x-bind:style="`width: ${micLevel}%`"></div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-2">
                @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'] as $key)
                    <flux:button x-on:click="pressKey('{{ $key }}')" x-bind:disabled="!inCall">{{ $key }}</flux:button>
                @endforeach
            </div>
            <flux:text class="text-xs" x-show="keypadBuffer !== ''" x-cloak>
                {{ __('Keyed:') }} <span class="font-mono" x-text="keypadBuffer"></span> — {{ __('press # to send') }}
            </flux:text>

            <flux:switch x-model="bargeIn" :label="__('Barge-in (speech interrupts prompts)')" />

            <form x-on:submit.prevent="sendTypedText()" class="space-y-2">
                <flux:input x-model="typedText" :label="__('Type instead of speaking')" :description="__('Skips Khaya — saves quota while testing the dialogue.')" placeholder="aane / me pe bosea / one thousand" x-bind:disabled="!inCall" />
            </form>

            <div class="rounded-lg bg-zinc-50 p-3 text-xs dark:bg-zinc-900">
                <div class="font-medium">{{ __('Khaya ASR') }} (<span x-text="quota.driver"></span>)</div>
                <div><span x-text="quota.used_this_month"></span> / <span x-text="quota.monthly_quota"></span> {{ __('calls used this month') }}, <span x-text="quota.per_minute_limit"></span>/{{ __('min limit') }}</div>
            </div>
        </flux:card>

        <flux:card class="flex min-h-[32rem] flex-col">
            <div class="mb-3 flex items-center justify-between">
                <flux:heading size="lg">{{ __('Conversation') }}</flux:heading>
                <flux:text class="text-sm" x-show="customerName" x-cloak x-text="customerName"></flux:text>
            </div>

            <flux:callout variant="danger" icon="exclamation-triangle" x-show="error" x-cloak class="mb-3">
                <flux:callout.text x-text="error"></flux:callout.text>
            </flux:callout>

            <div x-ref="log" class="flex-1 space-y-2 overflow-y-auto pr-1" style="max-height: 36rem">
                <template x-for="entry in log" x-bind:key="entry.id">
                    <div class="flex"
                        x-bind:class="{
                            'justify-end': entry.speaker === 'caller' || entry.speaker === 'heard',
                            'justify-center': entry.speaker === 'system',
                        }">
                        <div class="max-w-[85%] rounded-lg px-3 py-2 text-sm"
                            x-bind:class="{
                                'bg-zinc-100 dark:bg-zinc-700': entry.speaker === 'assistant',
                                'bg-blue-600 text-white': entry.speaker === 'caller',
                                'bg-blue-100 text-blue-900 dark:bg-blue-900 dark:text-blue-100': entry.speaker === 'heard',
                                'text-xs text-zinc-500': entry.speaker === 'system',
                            }">
                            <div class="mb-0.5 text-[10px] uppercase tracking-wide opacity-70" x-show="entry.speaker !== 'system'"
                                x-text="entry.speaker === 'heard' ? 'Recognised' : entry.speaker"></div>
                            <div x-text="entry.text"></div>
                        </div>
                    </div>
                </template>
                <flux:text x-show="log.length === 0" class="text-sm">{{ __('Press Call to start.') }}</flux:text>
            </div>
        </flux:card>
    </div>
</section>
