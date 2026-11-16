<?php

namespace App\Livewire\Customers;

use App\Enums\LoanApplicationStatus;
use App\Models\CallSession;
use App\Models\Customer;
use App\Models\Language;
use App\Models\LoanApplication;
use App\Services\Telephony\CallNotPlaced;
use App\Services\Telephony\TelephonyProvider;
use App\Support\Cedis;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Test panel: change a customer's balance, language and loan applications
 * so each voice scenario can be demonstrated.
 */
class Show extends Component
{
    public Customer $customer;

    public string $fullName = '';

    public string $balance = '0';

    public ?int $editingApplicationId = null;

    public string $applicationAmount = '';

    public string $applicationTerm = '';

    public string $applicationStatus = '';

    public string $nextRepaymentAmount = '';

    public string $nextRepaymentDueOn = '';

    public function mount(Customer $customer): void
    {
        $this->customer = $customer;
        $this->fullName = (string) $customer->full_name;
        $this->balance = number_format($customer->balance_pesewas / 100, 2, '.', '');
    }

    /**
     * Ring the customer's phone. When they answer, the IVR flow runs as if
     * they had called in: the published flow, or the draft to test it.
     */
    public function callPhone(TelephonyProvider $telephony, bool $useDraft = false): void
    {
        try {
            $telephony->placeCall($this->customer->phone_number, $useDraft);
        } catch (CallNotPlaced $exception) {
            Flux::toast(variant: 'danger', text: __('The call could not be placed: :reason', ['reason' => $exception->getMessage()]));

            return;
        }

        Flux::toast(variant: 'success', text: $useDraft
            ? __('Ringing :phone with the draft IVR flow.', ['phone' => $this->customer->phone_number])
            : __('Ringing :phone.', ['phone' => $this->customer->phone_number]));
    }

    /**
     * @return Collection<int, LoanApplication>
     */
    #[Computed]
    public function loanApplications(): Collection
    {
        return $this->customer->loanApplications()->latest()->get();
    }

    /**
     * @return Collection<int, CallSession>
     */
    #[Computed]
    public function recentCalls(): Collection
    {
        return $this->customer->callSessions()->with('turns')->latest()->limit(5)->get();
    }

    public function saveDetails(): void
    {
        $validated = $this->validate([
            'fullName' => ['nullable', 'string', 'max:100'],
            'balance' => ['required', 'numeric', 'min:-10000000', 'max:10000000'],
        ]);

        $this->customer->update([
            'full_name' => $validated['fullName'] ?: null,
            'balance_pesewas' => Cedis::toPesewas($validated['balance']),
        ]);

        Flux::toast(variant: 'success', text: __('Customer updated.'));
    }

    public function setLanguage(string $code): void
    {
        $chosen = Language::query()->where('code', $code)->where('enabled', true)->first();
        abort_if($chosen === null, 422);

        $this->customer->preferredLanguage()->associate($chosen)->save();
        Flux::toast(text: __('Language set to :language.', ['language' => $chosen->name]));
    }

    /**
     * Clear the saved language so the next call starts with the
     * language-choice prompt again.
     */
    public function resetLanguage(): void
    {
        $this->customer->update(['preferred_language_id' => null]);
        Flux::toast(text: __('Language reset. The next call will ask for a language.'));
    }

    public function newApplication(): void
    {
        $this->resetApplicationForm();
        $this->applicationStatus = LoanApplicationStatus::Pending->value;
        Flux::modal('loan-application')->show();
    }

    public function editApplication(int $applicationId): void
    {
        $application = $this->customer->loanApplications()->findOrFail($applicationId);

        $this->editingApplicationId = $application->id;
        $this->applicationAmount = number_format($application->amount_pesewas / 100, 2, '.', '');
        $this->applicationTerm = (string) $application->term_months;
        $this->applicationStatus = $application->status->value;
        $this->nextRepaymentAmount = $application->next_repayment_pesewas === null ? '' : number_format($application->next_repayment_pesewas / 100, 2, '.', '');
        $this->nextRepaymentDueOn = (string) $application->next_repayment_due_on?->toDateString();

        Flux::modal('loan-application')->show();
    }

    public function saveApplication(): void
    {
        $validated = $this->validate([
            'applicationAmount' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'applicationTerm' => ['required', 'integer', 'min:1', 'max:120'],
            'applicationStatus' => ['required', Rule::enum(LoanApplicationStatus::class)],
            'nextRepaymentAmount' => ['nullable', 'numeric', 'min:0', 'required_with:nextRepaymentDueOn'],
            'nextRepaymentDueOn' => ['nullable', 'date', 'required_with:nextRepaymentAmount'],
        ]);

        $attributes = [
            'amount_pesewas' => Cedis::toPesewas($validated['applicationAmount']),
            'term_months' => (int) $validated['applicationTerm'],
            'status' => $validated['applicationStatus'],
            'next_repayment_pesewas' => $validated['nextRepaymentAmount'] === null || $validated['nextRepaymentAmount'] === '' ? null : Cedis::toPesewas($validated['nextRepaymentAmount']),
            'next_repayment_due_on' => $validated['nextRepaymentDueOn'] ?: null,
        ];

        if ($this->editingApplicationId === null) {
            $this->customer->loanApplications()->create($attributes);
        } else {
            $this->customer->loanApplications()->findOrFail($this->editingApplicationId)->update($attributes);
        }

        $this->resetApplicationForm();
        unset($this->loanApplications);
        Flux::modal('loan-application')->close();
        Flux::toast(variant: 'success', text: __('Loan application saved.'));
    }

    public function deleteApplication(int $applicationId): void
    {
        $this->customer->loanApplications()->findOrFail($applicationId)->delete();
        unset($this->loanApplications);
        Flux::toast(text: __('Loan application deleted.'));
    }

    public function render(): View
    {
        return view('livewire.customers.show', [
            'languages' => Language::enabledInOrder(),
            'statuses' => LoanApplicationStatus::cases(),
            'missingTelephonySettings' => app(TelephonyProvider::class)->missingSettings(),
        ])->title($this->customer->full_name ?? $this->customer->phone_number);
    }

    private function resetApplicationForm(): void
    {
        $this->reset('editingApplicationId', 'applicationAmount', 'applicationTerm', 'applicationStatus', 'nextRepaymentAmount', 'nextRepaymentDueOn');
        $this->resetValidation();
    }
}
