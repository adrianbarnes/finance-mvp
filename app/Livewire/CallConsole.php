<?php

namespace App\Livewire;

use App\Models\Customer;
use App\Services\Khaya\KhayaQuotaGuard;
use App\Services\Telephony\CallNotPlaced;
use App\Services\Telephony\TelephonyProvider;
use App\Support\ConversationSettings;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Browser phone for testing the voice assistant before the IVR is connected.
 * The call itself runs in JavaScript (resources/js/call-console.js).
 */
#[Title('Call console')]
class CallConsole extends Component
{
    public bool $remembersLanguage = true;

    /**
     * Call the draft IVR flow instead of the published one, to try changes
     * before publishing them. Read by the browser phone when a call starts.
     */
    public bool $useDraft = false;

    /**
     * A real phone to ring through the telephony provider, typed locally
     * or with its country code.
     */
    public string $phoneToCall = '';

    public function mount(ConversationSettings $settings): void
    {
        $this->remembersLanguage = $settings->remembersLanguage();
    }

    /**
     * Toggle whether callers' language choices are saved and reused.
     */
    public function updatedRemembersLanguage(bool $value): void
    {
        app(ConversationSettings::class)->setRemembersLanguage($value);

        Flux::toast(text: $value
            ? __('Caller languages will be remembered. Returning customers skip the language prompt.')
            : __('Caller languages will not be remembered. Every call starts with the language prompt.'));
    }

    /**
     * Ring a real phone through the telephony provider. When it is
     * answered, the IVR flow runs as if that number had called in (the
     * draft with "Test draft").
     */
    public function callPhone(TelephonyProvider $telephony): void
    {
        $this->validate(
            ['phoneToCall' => ['required', 'phone:INTERNATIONAL,'.config('app.phone_country')]],
            ['phoneToCall.phone' => __('Enter a valid phone number, e.g. 0244123456.')],
        );

        $phoneNumber = (string) Customer::normalizePhoneNumber($this->phoneToCall);

        try {
            $telephony->placeCall($phoneNumber, $this->useDraft);
        } catch (CallNotPlaced $exception) {
            $this->addError('phoneToCall', $exception->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Ringing :phone. Answer it to talk to the assistant.', ['phone' => $phoneNumber]));
    }

    /**
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customers(): Collection
    {
        return Customer::query()->orderBy('full_name')->limit(200)->with('preferredLanguage')->get(['id', 'full_name', 'phone_number', 'preferred_language_id']);
    }

    public function render(): View
    {
        $telephony = app(TelephonyProvider::class);

        return view('livewire.call-console', [
            'quota' => app(KhayaQuotaGuard::class)->summary(),
            'missingTelephonySettings' => $telephony->missingSettings(),
        ]);
    }
}
