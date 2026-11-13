<?php

namespace App\Livewire\Customers;

use App\Enums\RegisteredVia;
use App\Models\Customer;
use App\Models\Language;
use App\Support\Cedis;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Test panel: list and add the customers the voice assistant serves.
 */
#[Title('Customers')]
class Index extends Component
{
    public string $search = '';

    public string $fullName = '';

    public string $phoneNumber = '';

    public string $preferredLanguage = '';

    public string $openingBalance = '0';

    /**
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customers(): Collection
    {
        return Customer::query()
            ->with('preferredLanguage')
            ->withCount('loanApplications')
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('full_name', 'like', "%{$this->search}%")
                ->orWhere('phone_number', 'like', '%'.Customer::normalizePhoneNumber($this->search).'%')))
            ->latest()
            ->limit(100)
            ->get();
    }

    public function addCustomer(): void
    {
        $this->phoneNumber = Customer::normalizePhoneNumber($this->phoneNumber);

        $validated = $this->validate([
            'fullName' => ['nullable', 'string', 'max:100'],
            'phoneNumber' => ['required', 'regex:/^233\d{9}$/', Rule::unique('customers', 'phone_number')],
            'preferredLanguage' => ['nullable', Rule::exists('languages', 'code')->where('enabled', true)->whereNull('deleted_at')],
            'openingBalance' => ['required', 'numeric', 'min:0', 'max:10000000'],
        ], [
            'phoneNumber.regex' => 'Enter a Ghana number, e.g. 0244123456.',
        ]);

        $customer = Customer::query()->create([
            'full_name' => $validated['fullName'] ?: null,
            'phone_number' => $validated['phoneNumber'],
            'preferred_language_id' => $validated['preferredLanguage'] ? Language::findByCode($validated['preferredLanguage'])?->id : null,
            'balance_pesewas' => Cedis::toPesewas($validated['openingBalance']),
            'registered_via' => RegisteredVia::Panel,
        ]);

        $this->reset('fullName', 'phoneNumber', 'preferredLanguage', 'openingBalance');
        Flux::modal('add-customer')->close();
        Flux::toast(variant: 'success', text: __('Customer :phone added.', ['phone' => $customer->phone_number]));
    }

    public function render(): View
    {
        return view('livewire.customers.index', [
            'languages' => Language::enabledInOrder(),
        ]);
    }
}
