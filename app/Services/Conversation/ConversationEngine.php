<?php

namespace App\Services\Conversation;

use App\Enums\CallChannel;
use App\Enums\CallDirection;
use App\Enums\CheckCondition;
use App\Enums\InputMode;
use App\Enums\LoanApplicationStatus;
use App\Enums\NumberUnit;
use App\Enums\Placeholder;
use App\Enums\PromptSlot;
use App\Enums\RegisteredVia;
use App\Enums\Speaker;
use App\Enums\StepAction;
use App\Enums\StepType;
use App\Models\AudioClip;
use App\Models\CallFlow;
use App\Models\CallSession;
use App\Models\Customer;
use App\Models\FlowStep;
use App\Models\Language;
use App\Models\LoanApplication;
use App\Services\Audio\AudioTranscoder;
use App\Services\Audio\AudioTranscodingFailed;
use App\Services\Audio\PreparedAudio;
use App\Services\Flows\FlowPublisher;
use App\Services\Flows\PromptAudioResolver;
use App\Services\Speech\DynamicSpeech;
use App\Services\Speech\SpeechQuotaExceeded;
use App\Services\Speech\SpeechRecognitionFailed;
use App\Services\Speech\SpeechRecognizer;
use App\Support\Cedis;
use App\Support\ConversationSettings;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Channel-agnostic voice dialogue that plays an IVR flow built in the
 * builder. The browser phone today, and the IVR later, feed caller input
 * in here and play back the reply.
 *
 * Steps run one after another until one waits for an answer (a choice, a
 * question or the language choice) or the call ends. When a branch's
 * steps run out, the call continues after the step that branched; when
 * the top-level steps run out, the call ends.
 */
class ConversationEngine
{
    /**
     * Steps entered in one turn before the flow is treated as looping.
     */
    private const int MaxStepsPerTurn = 100;

    /**
     * @var list<array{type: string, key?: string, text: string, audio_url?: string|null, language: string}>
     */
    private array $segments = [];

    /**
     * Set when the current turn's answer could not be understood.
     */
    private bool $misunderstood = false;

    /**
     * What the caller's answer was taken to mean in this turn.
     */
    private ?string $understood = null;

    private CallFlow $flow;

    /**
     * @var EloquentCollection<int, FlowStep>
     */
    private EloquentCollection $steps;

    /**
     * @var Collection<int|string, Collection<int, FlowStep>>
     */
    private Collection $children;

    /**
     * @var Collection<int, Language>
     */
    private Collection $languages;

    public function __construct(
        private SpeechRecognizer $speechRecognizer,
        private AudioTranscoder $audioTranscoder,
        private IntentResolver $intentResolver,
        private NumberParser $numberParser,
        private PromptAudioResolver $audioResolver,
        private FlowPublisher $publisher,
        private DynamicSpeech $dynamicSpeech,
        private ConversationSettings $settings,
    ) {}

    /**
     * Connect a call to the published flow, or to the draft when testing
     * it. A customer's saved language is used from the start.
     *
     * @return array{0: CallSession, 1: AssistantReply}
     */
    public function startCall(string $callerNumber, CallChannel $channel, CallDirection $direction = CallDirection::Inbound, bool $useDraft = false): array
    {
        $customer = Customer::findByPhoneNumber($callerNumber);
        $flow = $useDraft ? $this->publisher->draft() : $this->publisher->live();

        $call = CallSession::query()->create([
            'customer_id' => $customer?->id,
            'caller_number' => Customer::normalizePhoneNumber($callerNumber),
            'channel' => $channel,
            'direction' => $direction,
            'language_id' => $this->settings->remembersLanguage() ? $customer?->usableLanguage()?->id : null,
            'call_flow_id' => $flow->id,
            'state' => 'started',
            'context' => [],
        ]);

        $this->beginTurn($call);
        $this->runFrom($call, $this->firstStepIn(null));

        return [$call, $this->finishTurn($call, null)];
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

            return $this->recognitionUnavailable($call, $audioPath, counts: true);
        }

        if (! $prepared->hasSpeech()) {
            return $this->handleInput($call, CallInput::noInput($audioPath));
        }

        if (! $this->languageFor($call)->acceptsSpeech()) {
            return $this->recognitionUnavailable($call, $audioPath, counts: false);
        }

        try {
            $transcript = $this->speechRecognizer->transcribe($prepared->contents, PreparedAudio::MimeType, $this->languageFor($call), $call);
        } catch (SpeechQuotaExceeded) {
            return $this->recognitionUnavailable($call, $audioPath, counts: false);
        } catch (SpeechRecognitionFailed $exception) {
            Log::warning('Speech recognition failed', ['call' => $call->uuid, 'error' => $exception->errorCode]);

            return $this->recognitionUnavailable($call, $audioPath, counts: true);
        }

        $recognitionMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        if (trim($transcript) === '') {
            return $this->handleInput($call, CallInput::noInput($audioPath));
        }

        return $this->handleInput($call, CallInput::speech($transcript, $audioPath, $recognitionMs));
    }

    public function handleInput(CallSession $call, CallInput $input): AssistantReply
    {
        $this->beginTurn($call);
        $step = $this->currentStep($call);

        if ($step === null) {
            $this->recordCustomerTurn($call, $input);
            $this->endCall($call);

            return $this->finishTurn($call, $input->value);
        }

        if ($input->mode === InputMode::NoInput) {
            $this->recordCustomerTurn($call, $input);
            $this->handleNoInput($call, $step);

            return $this->finishTurn($call, null);
        }

        $this->forgetContext($call, 'no_input_replays');

        match ($step->type) {
            StepType::Choice => $this->answerChoice($call, $step, $input),
            StepType::AskNumber => $this->answerNumber($call, $step, $input),
            StepType::AskSpeech => $this->answerSpeech($call, $step, $input),
            StepType::ChooseLanguage => $this->answerLanguage($call, $step, $input),
            default => $this->runFrom($call, $this->nextAfter($step)),
        };

        $this->recordCustomerTurn($call, $input);
        $this->applyMisunderstandingLimit($call);

        return $this->finishTurn($call, $input->value);
    }

    public function hangUp(CallSession $call): void
    {
        if (! $call->hasEnded()) {
            $call->update(['state' => AssistantReply::Ended, 'current_step_id' => null, 'ended_at' => now()]);
        }
    }

    /**
     * Enter steps from $step until one waits for an answer or the call ends.
     */
    private function runFrom(CallSession $call, ?FlowStep $step): void
    {
        for ($entered = 0; $entered < self::MaxStepsPerTurn; $entered++) {
            if ($step === null) {
                $this->endCall($call);

                return;
            }

            switch ($step->type) {
                case StepType::Message:
                case StepType::Transfer:
                    $this->speakStep($call, $step, PromptSlot::Prompt);
                    $step = $this->nextAfter($step);
                    break;

                case StepType::Choice:
                case StepType::AskNumber:
                case StepType::AskSpeech:
                case StepType::ChooseLanguage:
                    $this->ask($call, $step);
                    $this->waitOn($call, $step);

                    return;

                case StepType::Check:
                    $branches = $this->branchesOf($step);
                    $branch = $this->conditionHolds($call, $step) ? $branches->firstWhere('is_otherwise', false) : $branches->firstWhere('is_otherwise', true);
                    $step = $branch === null ? $this->nextAfter($step) : $this->enterBranch($branch);
                    break;

                case StepType::Do:
                    $this->perform($call, $step);
                    $step = $this->nextAfter($step);
                    break;

                case StepType::GoTo:
                    $step = $this->steps->find($step->go_to_step_id) ?? $this->nextAfter($step);
                    break;

                case StepType::EndCall:
                    $this->endCall($call);

                    return;

                case StepType::Branch:
                    $step = $this->enterBranch($step);
                    break;
            }
        }

        Log::error('IVR flow looped without waiting for the caller', ['call' => $call->uuid, 'flow' => $this->flow->id]);
        $this->endCall($call);
    }

    /**
     * Speak a waiting step's question: its prompt, then for a choice each
     * option's announcement, or for the language choice each language's
     * own offer.
     */
    private function ask(CallSession $call, FlowStep $step): void
    {
        if ($step->type === StepType::ChooseLanguage) {
            $this->offerLanguages($step);

            return;
        }

        $this->speakStep($call, $step, PromptSlot::Prompt);

        if ($step->type === StepType::Choice) {
            foreach ($this->branchesOf($step)->where('is_otherwise', false) as $branch) {
                $this->speakStep($call, $branch, PromptSlot::Prompt);
            }
        }
    }

    /**
     * The answer was not understood: say so and ask again. The step's
     * "when not understood" audio is used when it has one.
     */
    private function askAgain(CallSession $call, FlowStep $step): void
    {
        $this->misunderstood = true;

        if ($step->promptItemsFor(PromptSlot::Reprompt)->isNotEmpty()) {
            $this->speakStep($call, $step, PromptSlot::Reprompt);

            return;
        }

        $this->speakSettingClip($call, 'not_understood_clip');
        $this->ask($call, $step);
    }

    private function answerChoice(CallSession $call, FlowStep $step, CallInput $input): void
    {
        $branches = $this->branchesOf($step);
        $branch = $input->mode === InputMode::Keypad
            ? $this->intentResolver->branchByKeypad($branches, $input->value)
            : $this->intentResolver->matchBranch($branches, $input->value, $this->languageFor($call));

        if ($branch === null) {
            $otherwise = $branches->firstWhere('is_otherwise', true);

            if ($otherwise !== null && $this->firstStepIn($otherwise->id) !== null) {
                $this->understood = $otherwise->name;
                $this->runFrom($call, $this->enterBranch($otherwise));

                return;
            }

            $this->askAgain($call, $step);

            return;
        }

        $this->understood = $branch->name;
        $this->runFrom($call, $this->enterBranch($branch));
    }

    private function answerNumber(CallSession $call, FlowStep $step, CallInput $input): void
    {
        $number = $this->numberParser->parse(rtrim($input->value, '#'), $this->languageFor($call));

        if ($number === null) {
            $this->askAgain($call, $step);

            return;
        }

        $unit = $this->unitOf($step);
        $minimum = (float) $step->configValue('min', 0);
        $maximum = (float) $step->configValue('max', PHP_INT_MAX);

        if ($number < $minimum || $number > $maximum || ($unit->isWhole() && floor($number) !== $number)) {
            $this->speakStep($call, $step, $step->promptItemsFor(PromptSlot::OutOfRange)->isNotEmpty() ? PromptSlot::OutOfRange : PromptSlot::Reprompt);
            $this->say($unit->spoken($minimum).' to '.$unit->spoken($maximum));
            $this->speakStep($call, $step, PromptSlot::Prompt);

            return;
        }

        $this->understood = $unit->spoken($number);
        $this->rememberAnswer($call, (string) $step->configValue('variable'), $number);
        $this->runFrom($call, $this->nextAfter($step));
    }

    private function answerSpeech(CallSession $call, FlowStep $step, CallInput $input): void
    {
        $answer = Str::limit(Str::title(trim(preg_replace('/[^\p{L}\s\'-]+/u', ' ', $input->value) ?? '')), 100, '');

        if ($input->mode === InputMode::Keypad || $answer === '') {
            $this->askAgain($call, $step);

            return;
        }

        $this->understood = $answer;
        $this->rememberAnswer($call, (string) $step->configValue('variable'), $answer);
        $this->runFrom($call, $this->nextAfter($step));
    }

    /**
     * Only an affirmative answer (the language's "yes", its name, or its
     * key) picks a language; anything else is asked again.
     */
    private function answerLanguage(CallSession $call, FlowStep $step, CallInput $input): void
    {
        $language = $input->mode === InputMode::Keypad
            ? $this->intentResolver->chosenLanguageByKeypad($input->value)
            : $this->intentResolver->chosenLanguage($input->value);

        if ($language === null) {
            $this->askAgain($call, $step);

            return;
        }

        $this->understood = $language->name;
        $call->language()->associate($language);

        if ($call->customer !== null && $this->settings->remembersLanguage()) {
            $call->customer->preferredLanguage()->associate($language)->save();
        }

        $this->runFrom($call, $this->nextAfter($step));
    }

    /**
     * Replay the question a limited number of times, then thank the caller
     * and end the call.
     */
    private function handleNoInput(CallSession $call, FlowStep $step): void
    {
        $replays = (int) $call->contextValue('no_input_replays', 0);

        if ($replays >= (int) $this->flow->setting('max_no_input_replays')) {
            $this->speakSettingClip($call, 'no_input_goodbye_clip');
            $this->endCall($call);

            return;
        }

        $this->rememberContext($call, 'no_input_replays', $replays + 1);
        $this->ask($call, $step);
    }

    /**
     * Respond when the caller's speech could not be used: explain, then
     * ask the current question again. Failed recognition counts as a
     * misunderstanding; a used-up quota or a keypad-only language asks for
     * the keypad instead.
     */
    private function recognitionUnavailable(CallSession $call, string $audioPath, bool $counts): AssistantReply
    {
        $this->beginTurn($call);
        $this->recordCustomerTurn($call, CallInput::speech('', $audioPath));
        $step = $this->currentStep($call);

        if ($step !== null) {
            if ($counts) {
                $this->askAgain($call, $step);
            } else {
                $this->speakSettingClip($call, 'speech_unavailable_clip');
                $this->ask($call, $step);
            }
        }

        $this->applyMisunderstandingLimit($call);
        $reply = $this->finishTurn($call, null);

        return new AssistantReply($reply->segments, $reply->state, null, null, suggestKeypad: ! $counts, stepType: $reply->stepType);
    }

    /**
     * Count answers in a row that were not understood; on the last one
     * allowed, explain that the call has to end.
     */
    private function applyMisunderstandingLimit(CallSession $call): void
    {
        if (! $this->misunderstood) {
            $this->forgetContext($call, 'misunderstood_attempts');

            return;
        }

        $attempts = (int) $call->contextValue('misunderstood_attempts', 0) + 1;

        if ($attempts >= (int) $this->flow->setting('max_misunderstood_attempts')) {
            $this->segments = [];
            $this->speakSettingClip($call, 'too_many_attempts_clip');
            $this->endCall($call);

            return;
        }

        $this->rememberContext($call, 'misunderstood_attempts', $attempts);
    }

    private function conditionHolds(CallSession $call, FlowStep $step): bool
    {
        $customer = $call->customer;

        return match (CheckCondition::tryFrom((string) $step->configValue('condition'))) {
            CheckCondition::LanguageSaved => $this->settings->remembersLanguage() && $customer?->usableLanguage() !== null,
            CheckCondition::KnownCaller => $customer !== null,
            CheckCondition::HasApplication => $customer?->latestLoanApplication()->exists() ?? false,
            CheckCondition::HasRepaymentDue => $customer?->loanApplications()->withRepaymentDue()->exists() ?? false,
            null => false,
        };
    }

    private function perform(CallSession $call, FlowStep $step): void
    {
        match (StepAction::tryFrom((string) $step->configValue('action'))) {
            StepAction::RegisterCaller => $this->registerCaller($call),
            StepAction::SubmitLoanApplication => $this->submitLoanApplication($call),
            null => null,
        };
    }

    private function registerCaller(CallSession $call): void
    {
        if ($call->customer !== null) {
            return;
        }

        $customer = Customer::query()->create([
            'full_name' => $this->answer($call, 'name'),
            'phone_number' => $call->caller_number,
            'preferred_language_id' => $this->settings->remembersLanguage() ? $call->language_id : null,
            'balance_pesewas' => 0,
            'registered_via' => RegisteredVia::Voice,
        ]);

        $call->customer()->associate($customer);
    }

    private function submitLoanApplication(CallSession $call): void
    {
        $amount = $this->answer($call, 'amount');
        $months = $this->answer($call, 'months');

        if ($call->customer === null || ! is_numeric($amount) || ! is_numeric($months)) {
            Log::warning('Loan application step ran without a customer, amount or months', ['call' => $call->uuid]);

            return;
        }

        LoanApplication::query()->create([
            'customer_id' => $call->customer_id,
            'call_session_id' => $call->id,
            'amount_pesewas' => Cedis::toPesewas($amount),
            'term_months' => (int) $months,
            'status' => LoanApplicationStatus::Pending,
        ]);
    }

    /**
     * Speak one slot of a step. The whole slot plays in one language: the
     * caller's, else the primary language, else the first with every clip.
     */
    private function speakStep(CallSession $call, FlowStep $step, PromptSlot $slot): void
    {
        $language = $this->audioResolver->languageFor($step, $slot, $this->languageFor($call), $this->languages) ?? $this->languageFor($call);

        foreach ($step->promptItemsFor($slot) as $item) {
            if ($item->clip !== null) {
                $this->speakClip($item->clip, $language);
            } else {
                $this->speakValue($call, (string) $item->placeholder, $language);
            }
        }
    }

    /**
     * Speak a clip named in the flow settings, e.g. "not understood".
     */
    private function speakSettingClip(CallSession $call, string $setting): void
    {
        $clip = $this->flow->clips->firstWhere('key', $this->flow->setting($setting));

        if ($clip !== null) {
            $language = $this->audioResolver->languageForClips(collect([$clip]), $this->languageFor($call), $this->languages) ?? $this->languageFor($call);
            $this->speakClip($clip, $language);
        }
    }

    /**
     * Each enabled language offers itself in its own language. A language
     * with no recording of the offer yet is offered with its wording,
     * which only the browser phone can read aloud.
     */
    private function offerLanguages(FlowStep $step): void
    {
        $clips = $step->promptItemsFor(PromptSlot::Prompt)->pluck('clip')->filter();

        foreach ($this->languages as $language) {
            $offered = $clips->every(fn (AudioClip $clip): bool => $clip->hasAudioIn($language) || filled($clip->recordingFor($language)?->text));

            if ($offered) {
                $clips->each(fn (AudioClip $clip) => $this->speakClip($clip, $language));
            }
        }
    }

    private function speakClip(AudioClip $clip, Language $language): void
    {
        $recording = $clip->recordingFor($language);

        $this->segments[] = [
            'type' => 'prompt',
            'key' => $clip->key,
            'text' => $recording->text ?? Str::headline($clip->key),
            'audio_url' => $recording?->audioUrl(),
            'language' => $language->code,
        ];
    }

    /**
     * Speak a value known only at call time. Values are read in English for
     * now, except an application's status, which plays its status clip.
     */
    private function speakValue(CallSession $call, string $placeholder, Language $language): void
    {
        if (str_starts_with($placeholder, 'answer:')) {
            $this->speakAnswer($call, substr($placeholder, 7));

            return;
        }

        $customer = $call->customer;
        $application = $customer?->latestLoanApplication()->first();
        $repayment = $customer?->loanApplications()->withRepaymentDue()->oldest('next_repayment_due_on')->first();

        match (Placeholder::tryFrom($placeholder)) {
            Placeholder::CustomerName => filled($customer?->full_name) ? $this->say((string) $customer->full_name) : null,
            Placeholder::Balance => $customer === null ? null : $this->say(Cedis::spoken($customer->balance_pesewas)),
            Placeholder::ApplicationAmount => $application === null ? null : $this->say(Cedis::spoken($application->amount_pesewas)),
            Placeholder::ApplicationReference => $application === null ? null : $this->say('reference '.implode(' ', mb_str_split($application->reference))),
            Placeholder::ApplicationStatus => $application === null ? null : $this->speakStatus($application->status->promptKey(), $application->status->label(), $language),
            Placeholder::RepaymentAmount => $repayment?->next_repayment_pesewas === null ? null : $this->say(Cedis::spoken($repayment->next_repayment_pesewas)),
            Placeholder::RepaymentDate => $repayment?->next_repayment_due_on === null ? null : $this->say($repayment->next_repayment_due_on->format('l, j F Y')),
            null => null,
        };
    }

    private function speakStatus(string $clipKey, string $label, Language $language): void
    {
        $clip = $this->flow->clips->firstWhere('key', $clipKey);

        if ($clip === null) {
            $this->say($label);

            return;
        }

        $this->speakClip($clip, $language);
    }

    private function speakAnswer(CallSession $call, string $name): void
    {
        $value = $this->answer($call, $name);

        if ($value === null) {
            return;
        }

        $asker = $this->steps->first(fn (FlowStep $step): bool => $step->type === StepType::AskNumber && $step->configValue('variable') === $name);

        $this->say($asker !== null && is_numeric($value) ? $this->unitOf($asker)->spoken((float) $value) : (string) $value);
    }

    /**
     * Dynamic content (amounts, dates, names, references) is spoken in English for now.
     */
    private function say(string $text): void
    {
        $this->segments[] = $this->dynamicSpeech->segment($text);
    }

    private function unitOf(FlowStep $step): NumberUnit
    {
        return NumberUnit::tryFrom((string) $step->configValue('unit')) ?? NumberUnit::Plain;
    }

    /**
     * Load the call's flow and reset what this turn will say.
     */
    private function beginTurn(CallSession $call): void
    {
        $this->segments = [];
        $this->misunderstood = false;
        $this->understood = null;

        $this->flow = $call->callFlow ?? $this->publisher->live();
        $this->flow->load('clips.recordings');
        $this->steps = $this->flow->steps()
            ->with(['keywords', 'promptItems.clip.recordings'])
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $this->children = $this->steps->toBase()->groupBy(fn (FlowStep $step): int|string => $step->parent_id ?? 'root');
        $this->languages = Language::enabledInOrder()->toBase();
    }

    private function currentStep(CallSession $call): ?FlowStep
    {
        return $call->current_step_id === null ? null : $this->steps->find($call->current_step_id);
    }

    /**
     * @return Collection<int, FlowStep>
     */
    private function branchesOf(FlowStep $step): Collection
    {
        return $this->children->get($step->id, collect());
    }

    private function firstStepIn(?int $branchId): ?FlowStep
    {
        return $this->children->get($branchId ?? 'root', collect())->first();
    }

    /**
     * The first step of a branch, or the step after the branching step
     * when the branch is empty.
     */
    private function enterBranch(FlowStep $branch): ?FlowStep
    {
        return $this->firstStepIn($branch->id) ?? $this->nextAfterBranch($branch);
    }

    /**
     * The step that follows: the next one in the same list, or, at the end
     * of a branch, the step after the one that branched.
     */
    private function nextAfter(FlowStep $step): ?FlowStep
    {
        $siblings = $this->children->get($step->parent_id ?? 'root', collect())->values();
        $index = $siblings->search(fn (FlowStep $sibling): bool => $sibling->is($step));
        $next = $index === false ? null : $siblings->get($index + 1);

        if ($next !== null) {
            return $next;
        }

        $branch = $step->parent_id === null ? null : $this->steps->find($step->parent_id);

        return $branch === null ? null : $this->nextAfterBranch($branch);
    }

    private function nextAfterBranch(FlowStep $branch): ?FlowStep
    {
        $branchingStep = $branch->parent_id === null ? null : $this->steps->find($branch->parent_id);

        return $branchingStep === null ? null : $this->nextAfter($branchingStep);
    }

    private function waitOn(CallSession $call, FlowStep $step): void
    {
        $call->current_step_id = $step->id;
        $call->state = $step->type->value;
    }

    private function endCall(CallSession $call): void
    {
        $call->current_step_id = null;
        $call->state = AssistantReply::Ended;
    }

    /**
     * The call's language, or the primary language until the caller chooses.
     */
    private function languageFor(CallSession $call): Language
    {
        return $call->language ?? Language::primary();
    }

    private function rememberAnswer(CallSession $call, string $name, float|string $value): void
    {
        $this->rememberContext($call, 'answers', [...(array) $call->contextValue('answers', []), $name => $value]);
    }

    private function answer(CallSession $call, string $name): mixed
    {
        return data_get($call->context, "answers.{$name}");
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

    private function recordCustomerTurn(CallSession $call, CallInput $input): void
    {
        $call->turns()->create([
            'speaker' => Speaker::Customer,
            'input_mode' => $input->mode,
            'text' => $input->value,
            'intent' => $this->understood,
            'audio_path' => $input->audioPath,
            'recognition_ms' => $input->recognitionMs,
        ]);
    }

    private function finishTurn(CallSession $call, ?string $heard): AssistantReply
    {
        $waitingOn = $this->currentStep($call);
        $state = $call->state === AssistantReply::Ended || $waitingOn === null ? AssistantReply::Ended : $waitingOn->name;
        $reply = new AssistantReply($this->segments, $state, $heard, $this->understood, stepType: $state === AssistantReply::Ended ? null : $waitingOn?->type->value);

        DB::transaction(function () use ($call, $reply): void {
            if ($reply->hasEnded()) {
                $call->current_step_id = null;
                $call->state = AssistantReply::Ended;
                $call->ended_at ??= now();
            }

            $call->save();

            $call->turns()->create([
                'speaker' => Speaker::Assistant,
                'text' => $reply->text(),
                'state_after' => $reply->state,
            ]);
        });

        return $reply;
    }
}
