<section class="w-full space-y-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:link :href="route('customers.index')" wire:navigate class="text-sm">&larr; {{ __('Customers') }}</flux:link>
            <flux:heading size="xl" level="1" class="mt-2">{{ $customer->full_name ?? __('(no name)') }}</flux:heading>
            <flux:subheading>{{ $customer->phone_number }} &middot; {{ __('registered via :via', ['via' => $customer->registered_via->value]) }}</flux:subheading>
        </div>

        <div class="flex flex-col items-end gap-1">
            <div class="flex gap-2">
                <flux:button variant="primary" icon="phone-arrow-up-right" wire:click="callPhone" :disabled="$missingTelephonySettings !== []">
                    {{ __('Call customer') }}
                </flux:button>
                <flux:button icon="beaker" wire:click="callPhone(true)" :disabled="$missingTelephonySettings !== []">
                    {{ __('Call with draft') }}
                </flux:button>
            </div>
            @if ($missingTelephonySettings !== [])
                <flux:text class="text-xs">{{ __('Set :settings in .env to call phones.', ['settings' => implode(', ', $missingTelephonySettings)]) }}</flux:text>
            @endif
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <flux:card class="space-y-6">
            <flux:heading size="lg">{{ __('Details and balance') }}</flux:heading>

            <form wire:submit="saveDetails" class="space-y-4">
                <flux:input wire:model="fullName" :label="__('Full name')" />
                <flux:input wire:model="balance" :label="__('Balance (GHS)')" type="number" step="0.01" required />
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </form>
        </flux:card>

        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('Call language') }}</flux:heading>

            @unless (app(\App\Support\ConversationSettings::class)->remembersLanguage())
                <flux:callout variant="warning" icon="information-circle">
                    <flux:callout.text>{{ __('Remember caller languages is switched off on the call console. Every call starts with the language prompt and the saved language below is ignored.') }}</flux:callout.text>
                </flux:callout>
            @endunless

            <flux:text>
                @if ($customer->usableLanguage())
                    {{ __('Calls start in :language without asking.', ['language' => $customer->usableLanguage()->name]) }}
                @elseif ($customer->preferredLanguage)
                    {{ __(':language is saved but switched off on the Languages page, so the next call asks for a language.', ['language' => $customer->preferredLanguage->name]) }}
                @else
                    {{ __('No language saved. The next call starts with the language-choice prompt.') }}
                @endif
            </flux:text>

            <div class="flex flex-wrap gap-2">
                @foreach ($languages as $language)
                    <flux:button size="sm" wire:click="setLanguage('{{ $language->code }}')" :variant="$customer->preferred_language_id === $language->id ? 'primary' : 'filled'">
                        {{ $language->name }}
                    </flux:button>
                @endforeach

                <flux:button size="sm" variant="danger" icon="arrow-path" wire:click="resetLanguage" :disabled="$customer->preferred_language_id === null">
                    {{ __('Reset (ask on next call)') }}
                </flux:button>
            </div>
        </flux:card>
    </div>

    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">{{ __('Loan applications') }}</flux:heading>
            <flux:button size="sm" icon="plus" wire:click="newApplication">{{ __('Add application') }}</flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Reference') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Term') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Next repayment') }}</flux:table.column>
                <flux:table.column>{{ __('Source') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->loanApplications as $application)
                    <flux:table.row :key="$application->id">
                        <flux:table.cell>{{ $application->reference }}</flux:table.cell>
                        <flux:table.cell align="end">{{ \App\Support\Cedis::format($application->amount_pesewas) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ trans_choice(':count month|:count months', $application->term_months) }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$application->status->badgeColor()">{{ $application->status->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($application->next_repayment_pesewas !== null)
                                {{ \App\Support\Cedis::format($application->next_repayment_pesewas) }} &middot; {{ $application->next_repayment_due_on?->format('j M Y') }}
                            @else
                                &mdash;
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $application->call_session_id ? __('Voice') : __('Panel') }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editApplication({{ $application->id }})" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteApplication({{ $application->id }})" wire:confirm="{{ __('Delete this loan application?') }}" />
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7">{{ __('No loan applications.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <flux:text class="text-sm">{{ __('Repayment due is only read out for applications with status Disbursed and a next repayment set.') }}</flux:text>
    </div>

    <div class="space-y-4">
        <flux:heading size="lg">{{ __('Recent calls') }}</flux:heading>

        @forelse ($this->recentCalls as $call)
            <flux:card wire:key="call-{{ $call->id }}" class="space-y-3">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <flux:badge size="sm">{{ $call->started_at->format('j M Y H:i') }}</flux:badge>
                    <flux:badge size="sm">{{ $call->language?->name ?? __('No language') }}</flux:badge>
                    <flux:badge size="sm" :color="$call->hasEnded() ? 'zinc' : 'green'">{{ $call->hasEnded() ? __('Ended') : __('Active') }}</flux:badge>
                </div>

                <div class="space-y-2 text-sm">
                    @foreach ($call->turns as $turn)
                        <div wire:key="turn-{{ $turn->id }}" class="flex flex-wrap items-start gap-2">
                            <span class="w-20 shrink-0 font-medium">{{ $turn->speaker === \App\Enums\Speaker::Customer ? __('Caller') : __('Assistant') }}</span>
                            <span class="flex-1">
                                @if ($turn->speaker === \App\Enums\Speaker::Customer)
                                    <span class="text-zinc-500">[{{ $turn->input_mode?->value }}{{ $turn->intent ? ' → '.$turn->intent : '' }}]</span>
                                @endif
                                {{ $turn->text }}
                            </span>
                            @if ($turn->audio_path)
                                <audio controls preload="none" src="{{ route('call-turns.audio', $turn) }}" class="h-8"></audio>
                            @endif
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @empty
            <flux:text>{{ __('No calls yet.') }}</flux:text>
        @endforelse
    </div>

    <flux:modal name="loan-application" class="md:w-[28rem]">
        <form wire:submit="saveApplication" class="space-y-5">
            <flux:heading size="lg">{{ $editingApplicationId ? __('Edit loan application') : __('Add loan application') }}</flux:heading>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="applicationAmount" :label="__('Amount (GHS)')" type="number" step="0.01" min="1" required />
                <flux:input wire:model="applicationTerm" :label="__('Term (months)')" type="number" min="1" required />
            </div>

            <flux:select wire:model="applicationStatus" :label="__('Status')">
                @foreach ($statuses as $status)
                    <flux:select.option :value="$status->value">{{ $status->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="nextRepaymentAmount" :label="__('Next repayment (GHS)')" type="number" step="0.01" min="0" />
                <flux:input wire:model="nextRepaymentDueOn" :label="__('Due on')" type="date" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
