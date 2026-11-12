<?php

namespace App\Services\Conversation;

use App\Enums\CallChannel;
use App\Enums\CallDirection;
use App\Enums\CallState;
use App\Enums\InputMode;
use App\Enums\Intent;
use App\Enums\Language;
use App\Enums\LoanApplicationStatus;
use App\Enums\RegisteredVia;
use App\Enums\Speaker;
use App\Models\CallSession;
use App\Models\Customer;
use App\Models\LoanApplication;
use App\Services\Audio\AudioTranscoder;
use App\Services\Audio\AudioTranscodingFailed;
use App\Services\Audio\PreparedAudio;
use App\Services\Speech\DynamicSpeech;
use App\Services\Speech\SpeechQuotaExceeded;
use App\Services\Speech\SpeechRecognitionFailed;
use App\Services\Speech\SpeechRecognizer;
use App\Support\Cedis;
use App\Support\ConversationSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Channel-agnostic voice dialogue. The browser phone today, and the IVR
 * adapters later, feed caller input in here and play back the reply.
 */
class ConversationEngine
{
    /**
     * @var list<array{type: string, key?: string, text: string, audio_url?: string|null, language: string}>
     */
    private array $segments = [];

    public function __construct(
        private SpeechRecognizer $speechRecognizer,
        private AudioTranscoder $audioTranscoder,
        private IntentResolver $intentResolver,
        private NumberParser $numberParser,
        private VoicePromptLibrary $prompts,
        private DynamicSpeech $dynamicSpeech,
        private ConversationSettings $settings,
    ) {}

    /**
     * Connect a call. Customers with a saved language go straight to the
     * main menu; everyone else first hears the language-choice prompt.
     *
     * @return array{0: CallSession, 1: AssistantReply}
     */
    public function startCall(string $callerNumber, CallChannel $channel, CallDirection $direction = CallDirection::Inbound): array
    {
        $customer = Customer::findByPhoneNumber($callerNumber);
        $knownLanguage = $this->remembersLanguage() ? $customer?->preferred_language : null;

        $call = CallSession::query()->create([
            'customer_id' => $customer?->id,
            'caller_number' => Customer::normalizePhoneNumber($callerNumber),
            'channel' => $channel,
            'direction' => $direction,
            'language' => $knownLanguage,
            'state' => CallState::ChooseLanguage,
            'context' => [],
        ]);

        $this->segments = [];

        if ($knownLanguage !== null) {
            $this->greet($call);
            $state = CallState::MainMenu;
        } else {
            $this->offerLanguages();
            $state = CallState::ChooseLanguage;
        }

        return [$call, $this->finishTurn($call, $state, null, null)];
    }

    /**
     * Clean up the caller's audio with ffmpeg, transcribe it, then respond.
     * Audio that is only silence or noise counts as no input.
     */
    public function handleSpeech(CallSession $call, string $audio, string $mimeType): AssistantReply
    {
        $audioPath = 'call-audio/'.$call->uuid.'/'.Str::ulid().'.'.$this->extensionFor($mimeType);
        Storage::disk('local')->put($audioPath, $audio);

        $startedAt = hrtime(true);

        try {
            $prepared = $this->audioTranscoder->prepareForRecognition($audio);
        } catch (AudioTranscodingFailed $exception) {
            Log::warning('Caller audio could not be converted', ['call' => $call->uuid, 'error' => $exception->getMessage()]);

            return $this->recognitionUnavailable($call, $audioPath, 'not_understood', suggestKeypad: false);
        }

        if (! $prepared->hasSpeech()) {
            return $this->handleInput($call, CallInput::noInput($audioPath));
        }

        try {
            $transcript = $this->speechRecognizer->transcribe($prepared->contents, PreparedAudio::MimeType, $this->languageFor($call), $call);
        } catch (SpeechQuotaExceeded) {
            return $this->recognitionUnavailable($call, $audioPath, 'speech_unavailable', suggestKeypad: true);
        } catch (SpeechRecognitionFailed $exception) {
            Log::warning('Speech recognition failed', ['call' => $call->uuid, 'error' => $exception->errorCode]);

            return $this->recognitionUnavailable($call, $audioPath, 'not_understood', suggestKeypad: false);
        }

        $recognitionMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        if (trim($transcript) === '') {
            return $this->handleInput($call, CallInput::noInput($audioPath));
        }

        return $this->handleInput($call, CallInput::speech($transcript, $audioPath, $recognitionMs));
    }

    public function handleInput(CallSession $call, CallInput $input): AssistantReply
    {
        $this->segments = [];

        if ($input->mode === InputMode::NoInput) {
            $this->recordCustomerTurn($call, $input, null);

            return $this->finishTurn($call, $this->handleNoInput($call), null, null);
        }

        $this->forgetContext($call, 'no_input_replays');
        $intent = $this->resolveIntent($call, $input);
        $this->recordCustomerTurn($call, $input, $intent);

        $nextState = match ($call->state) {
            CallState::ChooseLanguage => $this->handleLanguageChoice($call, $input),
            CallState::OfferRegistration => $this->handleRegistrationOffer($call, $intent),
            CallState::AskName => $this->handleName($call, $input),
            CallState::ConfirmName => $this->handleNameConfirmation($call, $intent),
            CallState::MainMenu => $this->handleMenuChoice($call, $intent),
            CallState::ApplyAmount => $this->handleLoanAmount($call, $input, $intent),
            CallState::ApplyTerm => $this->handleLoanTerm($call, $input, $intent),
            CallState::ApplyConfirm => $this->handleLoanConfirmation($call, $intent),
            CallState::AnythingElse => $this->handleAnythingElse($call, $intent),
            CallState::Ended => CallState::Ended,
        };

        return $this->finishTurn($call, $nextState, $input->value, $intent);
    }

    public function hangUp(CallSession $call): void
    {
        if (! $call->hasEnded()) {
            $call->update(['state' => CallState::Ended, 'ended_at' => now()]);
        }
    }

    /**
     * Replay the current question a limited number of times, then thank
     * the caller and end the call.
     */
    private function handleNoInput(CallSession $call): CallState
    {
        $replays = (int) $call->contextValue('no_input_replays', 0);

        if ($replays >= (int) config('conversation.max_no_input_replays')) {
            $this->prompt($call, 'goodbye');

            return CallState::Ended;
        }

        $this->rememberContext($call, 'no_input_replays', $replays + 1);
        $this->askCurrentQuestion($call);

        return $call->state;
    }

    /**
     * Only an affirmative answer (the language's "yes", its name, or its
     * keypad number) continues the call; anything else ends it politely.
     */
    private function handleLanguageChoice(CallSession $call, CallInput $input): CallState
    {
        $language = $input->mode === InputMode::Keypad
            ? $this->intentResolver->chosenLanguageByKeypad($input->value)
            : $this->intentResolver->chosenLanguage($input->value);

        if ($language === null) {
            $this->prompt($call, 'goodbye');

            return CallState::Ended;
        }

        $call->language = $language;
        $customer = $call->customer;

        if ($customer !== null) {
            if ($this->remembersLanguage()) {
                $customer->update(['preferred_language' => $language]);
            }

            $this->greet($call);

            return CallState::MainMenu;
        }

        $this->prompt($call, 'offer_registration');

        return CallState::OfferRegistration;
    }

    private function handleRegistrationOffer(CallSession $call, Intent $intent): CallState
    {
        return match ($intent) {
            Intent::Yes => $this->promptAndMoveTo($call, ['ask_name'], CallState::AskName),
            Intent::No, Intent::Goodbye => $this->promptAndMoveTo($call, ['goodbye'], CallState::Ended),
            default => $this->promptAndMoveTo($call, ['yes_no_hint', 'offer_registration'], CallState::OfferRegistration),
        };
    }

    private function handleName(CallSession $call, CallInput $input): CallState
    {
        $name = Str::limit(Str::title(trim(preg_replace('/[^\p{L}\s\'-]+/u', ' ', $input->value) ?? '')), 100, '');

        if ($input->mode === InputMode::Keypad || $name === '') {
            $this->prompt($call, 'not_understood');
            $this->prompt($call, 'ask_name');

            return CallState::AskName;
        }

        $this->rememberContext($call, 'pending_name', $name);
        $this->prompt($call, 'confirm_name');
        $this->say($name);
        $this->prompt($call, 'confirm_name_question');

        return CallState::ConfirmName;
    }

    private function handleNameConfirmation(CallSession $call, Intent $intent): CallState
    {
        if ($intent === Intent::Yes) {
            $customer = Customer::query()->create([
                'full_name' => $call->contextValue('pending_name'),
                'phone_number' => $call->caller_number,
                'preferred_language' => $this->remembersLanguage() ? $call->language : null,
                'balance_pesewas' => 0,
                'registered_via' => RegisteredVia::Voice,
            ]);

            $call->customer()->associate($customer);
            $this->forgetContext($call, 'pending_name');
            $this->prompt($call, 'registered');
            $this->prompt($call, 'main_menu');

            return CallState::MainMenu;
        }

        if ($intent === Intent::No) {
            return $this->promptAndMoveTo($call, ['ask_name'], CallState::AskName);
        }

        return $this->promptAndMoveTo($call, ['yes_no_hint', 'confirm_name_question'], CallState::ConfirmName);
    }

    private function handleMenuChoice(CallSession $call, Intent $intent): CallState
    {
        $customer = $call->customer;

        switch ($intent) {
            case Intent::CheckBalance:
                $this->prompt($call, 'balance_is');
                $this->say(Cedis::spoken($customer->balance_pesewas));
                break;

            case Intent::ApplicationStatus:
                $application = $customer->latestLoanApplication()->first();

                if ($application === null) {
                    $this->prompt($call, 'no_application');
                    break;
                }

                $this->prompt($call, 'application_status_intro');
                $this->say(Cedis::spoken($application->amount_pesewas).', reference '.$this->spell($application->reference));
                $this->prompt($call, $application->status->promptKey());
                break;

            case Intent::RepaymentDue:
                $loan = $customer->loanApplications()->withRepaymentDue()->oldest('next_repayment_due_on')->first();

                if ($loan === null) {
                    $this->prompt($call, 'no_repayment_due');
                    break;
                }

                $this->prompt($call, 'repayment_due_is');
                $this->say(Cedis::spoken($loan->next_repayment_pesewas));
                $this->prompt($call, 'repayment_due_on');
                $this->say($loan->next_repayment_due_on->format('l, j F Y'));
                break;

            case Intent::ApplyLoan:
                $this->forgetContext($call, 'amount_pesewas', 'term_months');
                $this->prompt($call, 'ask_amount');

                return CallState::ApplyAmount;

            case Intent::SpeakToAgent:
                return $this->promptAndMoveTo($call, ['agent_unavailable', 'main_menu'], CallState::MainMenu);

            case Intent::Goodbye:
            case Intent::No:
                return $this->promptAndMoveTo($call, ['goodbye'], CallState::Ended);

            case Intent::Repeat:
                return $this->promptAndMoveTo($call, ['main_menu'], CallState::MainMenu);

            default:
                return $this->promptAndMoveTo($call, ['not_understood', 'main_menu'], CallState::MainMenu);
        }

        $this->prompt($call, 'anything_else');

        return CallState::AnythingElse;
    }

    private function handleLoanAmount(CallSession $call, CallInput $input, Intent $intent): CallState
    {
        $amount = $this->parseNumber($call, $input);

        if ($amount === null && in_array($intent, [Intent::No, Intent::Goodbye], true)) {
            return $this->cancelApplication($call);
        }

        if ($amount === null) {
            return $this->promptAndMoveTo($call, ['amount_not_understood', 'ask_amount'], CallState::ApplyAmount);
        }

        $minimum = (int) config('conversation.loan.min_amount_cedis');
        $maximum = (int) config('conversation.loan.max_amount_cedis');

        if ($amount < $minimum || $amount > $maximum) {
            $this->prompt($call, 'amount_out_of_range');
            $this->say(Cedis::spoken($minimum * 100).' to '.Cedis::spoken($maximum * 100));
            $this->prompt($call, 'ask_amount');

            return CallState::ApplyAmount;
        }

        $this->rememberContext($call, 'amount_pesewas', Cedis::toPesewas($amount));
        $this->prompt($call, 'ask_term');

        return CallState::ApplyTerm;
    }

    private function handleLoanTerm(CallSession $call, CallInput $input, Intent $intent): CallState
    {
        $months = $this->parseNumber($call, $input);

        if ($months === null && in_array($intent, [Intent::No, Intent::Goodbye], true)) {
            return $this->cancelApplication($call);
        }

        $maximum = (int) config('conversation.loan.max_term_months');

        if ($months === null || $months < 1 || $months > $maximum || floor($months) !== $months) {
            $this->prompt($call, 'term_invalid');
            $this->say("1 to {$maximum} months");
            $this->prompt($call, 'ask_term');

            return CallState::ApplyTerm;
        }

        $this->rememberContext($call, 'term_months', (int) $months);
        $this->prompt($call, 'confirm_application');
        $this->say(Cedis::spoken($call->contextValue('amount_pesewas')).' for '.(int) $months.' '.Str::plural('month', (int) $months));
        $this->prompt($call, 'confirm_question');

        return CallState::ApplyConfirm;
    }

    private function handleLoanConfirmation(CallSession $call, Intent $intent): CallState
    {
        if ($intent === Intent::Yes) {
            $application = LoanApplication::query()->create([
                'customer_id' => $call->customer_id,
                'call_session_id' => $call->id,
                'amount_pesewas' => $call->contextValue('amount_pesewas'),
                'term_months' => $call->contextValue('term_months'),
                'status' => LoanApplicationStatus::Pending,
            ]);

            $this->forgetContext($call, 'amount_pesewas', 'term_months');
            $this->prompt($call, 'application_submitted');
            $this->say($this->spell($application->reference));
            $this->prompt($call, 'anything_else');

            return CallState::AnythingElse;
        }

        if (in_array($intent, [Intent::No, Intent::Goodbye], true)) {
            return $this->cancelApplication($call);
        }

        return $this->promptAndMoveTo($call, ['yes_no_hint', 'confirm_question'], CallState::ApplyConfirm);
    }

    private function handleAnythingElse(CallSession $call, Intent $intent): CallState
    {
        if (in_array($intent, Intent::menuActions(), true)) {
            return $this->handleMenuChoice($call, $intent);
        }

        return match ($intent) {
            Intent::Yes => $this->promptAndMoveTo($call, ['main_menu'], CallState::MainMenu),
            Intent::No, Intent::Goodbye => $this->promptAndMoveTo($call, ['goodbye'], CallState::Ended),
            Intent::Repeat => $this->promptAndMoveTo($call, ['anything_else'], CallState::AnythingElse),
            default => $this->promptAndMoveTo($call, ['yes_no_hint', 'anything_else'], CallState::AnythingElse),
        };
    }

    private function cancelApplication(CallSession $call): CallState
    {
        $this->forgetContext($call, 'amount_pesewas', 'term_months');

        return $this->promptAndMoveTo($call, ['application_cancelled', 'main_menu'], CallState::MainMenu);
    }

    /**
     * Respond when the utterance could not be transcribed: explain, then
     * ask the current question again.
     */
    private function recognitionUnavailable(CallSession $call, string $audioPath, string $promptKey, bool $suggestKeypad): AssistantReply
    {
        $this->segments = [];
        $this->recordCustomerTurn($call, CallInput::speech('', $audioPath), Intent::Unknown);

        $this->prompt($call, $promptKey);
        $this->askCurrentQuestion($call);

        $reply = $this->finishTurn($call, $call->state, null, Intent::Unknown);

        return new AssistantReply($reply->segments, $reply->state, null, Intent::Unknown, $suggestKeypad);
    }

    /**
     * The language prompt: each enabled language offers itself, in its own
     * language, played back to back as one prompt.
     */
    private function offerLanguages(): void
    {
        foreach (Language::enabled() as $language) {
            $this->segments[] = $this->prompts->segment('choose_language', $language);
        }
    }

    private function askCurrentQuestion(CallSession $call): void
    {
        if ($call->state === CallState::ChooseLanguage) {
            $this->offerLanguages();

            return;
        }

        $this->prompt($call, $call->state->questionPromptKey());
    }

    private function greet(CallSession $call): void
    {
        $this->prompt($call, 'greeting');

        if (filled($call->customer?->full_name)) {
            $this->say($call->customer->full_name);
        }

        $this->prompt($call, 'main_menu');
    }

    private function resolveIntent(CallSession $call, CallInput $input): Intent
    {
        $expectsConfirmation = $call->state->expectsConfirmation();

        if ($input->mode === InputMode::Keypad) {
            return $this->intentResolver->resolveKeypad($input->value, $expectsConfirmation);
        }

        return $this->intentResolver->resolve($input->value, $this->languageFor($call), $expectsConfirmation);
    }

    private function parseNumber(CallSession $call, CallInput $input): ?float
    {
        if ($input->mode === InputMode::Keypad) {
            $digits = rtrim($input->value, '#');

            return ctype_digit($digits) ? (float) $digits : null;
        }

        return $this->numberParser->parse($input->value, $this->languageFor($call));
    }

    /**
     * Whether customers' language choices are saved and reused on later calls.
     */
    private function remembersLanguage(): bool
    {
        return $this->settings->remembersLanguage();
    }

    /**
     * The call's language, or the default language until the caller chooses.
     */
    private function languageFor(CallSession $call): Language
    {
        return $call->language ?? Language::from(config('conversation.default_language'));
    }

    /**
     * @param  list<string>  $promptKeys
     */
    private function promptAndMoveTo(CallSession $call, array $promptKeys, CallState $state): CallState
    {
        foreach ($promptKeys as $promptKey) {
            $this->prompt($call, $promptKey);
        }

        return $state;
    }

    private function prompt(CallSession $call, string $key): void
    {
        $this->segments[] = $this->prompts->segment($key, $this->languageFor($call));
    }

    /**
     * Dynamic content (amounts, dates, names, references) is spoken in English for now.
     */
    private function say(string $text): void
    {
        $this->segments[] = $this->dynamicSpeech->segment($text);
    }

    /**
     * Read a reference out character by character, e.g. "L N 4 8 2 7".
     */
    private function spell(string $reference): string
    {
        return implode(' ', mb_str_split($reference));
    }

    private function rememberContext(CallSession $call, string $key, mixed $value): void
    {
        $call->context = [...($call->context ?? []), $key => $value];
    }

    private function forgetContext(CallSession $call, string ...$keys): void
    {
        $call->context = array_diff_key($call->context ?? [], array_flip($keys));
    }

    private function extensionFor(string $mimeType): string
    {
        return match (true) {
            str_contains($mimeType, 'webm') => 'webm',
            str_contains($mimeType, 'ogg') => 'ogg',
            str_contains($mimeType, 'mpeg') => 'mp3',
            str_contains($mimeType, 'flac') => 'flac',
            default => 'wav',
        };
    }

    private function recordCustomerTurn(CallSession $call, CallInput $input, ?Intent $intent): void
    {
        $call->turns()->create([
            'speaker' => Speaker::Customer,
            'input_mode' => $input->mode,
            'text' => $input->value,
            'intent' => $intent,
            'audio_path' => $input->audioPath,
            'recognition_ms' => $input->recognitionMs,
        ]);
    }

    private function finishTurn(CallSession $call, CallState $state, ?string $heard, ?Intent $intent): AssistantReply
    {
        $reply = new AssistantReply($this->segments, $state, $heard, $intent);

        DB::transaction(function () use ($call, $state, $reply): void {
            $call->state = $state;

            if ($state === CallState::Ended && $call->ended_at === null) {
                $call->ended_at = now();
            }

            $call->save();

            $call->turns()->create([
                'speaker' => Speaker::Assistant,
                'text' => $reply->text(),
                'state_after' => $state,
            ]);
        });

        return $reply;
    }
}
