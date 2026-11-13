<section class="w-full space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Customers') }}</flux:heading>
            <flux:subheading>{{ __('Test data for the voice assistant. Callers are identified by phone number.') }}</flux:subheading>
        </div>

        <flux:modal.trigger name="add-customer">
            <flux:button variant="primary" icon="plus">{{ __('Add customer') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name or phone number')" class="max-w-sm" />

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Phone number') }}</flux:table.column>
            <flux:table.column>{{ __('Language') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Balance') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Loan applications') }}</flux:table.column>
            <flux:table.column>{{ __('Registered via') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->customers as $customer)
                <flux:table.row :key="$customer->id">
                    <flux:table.cell>
                        <flux:link :href="route('customers.show', $customer)" wire:navigate>
                            {{ $customer->full_name ?? __('(no name)') }}
                        </flux:link>
                    </flux:table.cell>
                    <flux:table.cell>{{ $customer->phone_number }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($customer->preferredLanguage)
                            <flux:badge size="sm" :color="$customer->preferredLanguage->enabled ? 'zinc' : 'amber'">{{ $customer->preferredLanguage->name }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="amber">{{ __('Ask on call') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">{{ $customer->formattedBalance() }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $customer->loan_applications_count }}</flux:table.cell>
                    <flux:table.cell>{{ ucfirst($customer->registered_via->value) }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">{{ __('No customers yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="add-customer" class="md:w-96">
        <form wire:submit="addCustomer" class="space-y-6">
            <flux:heading size="lg">{{ __('Add customer') }}</flux:heading>

            <flux:input wire:model="phoneNumber" :label="__('Phone number')" placeholder="0244123456" required />
            <flux:input wire:model="fullName" :label="__('Full name')" />

            <flux:select wire:model="preferredLanguage" :label="__('Language')">
                <flux:select.option value="">{{ __('Ask on first call') }}</flux:select.option>
                @foreach ($languages as $language)
                    <flux:select.option :value="$language->code">{{ $language->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="openingBalance" :label="__('Opening balance (GHS)')" type="number" step="0.01" min="0" required />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Add customer') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
