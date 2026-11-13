<?php

namespace App\Services\Flows;

use App\Enums\BranchMatch;
use App\Enums\CheckCondition;
use App\Enums\FlowStatus;
use App\Enums\LoanApplicationStatus;
use App\Enums\NumberUnit;
use App\Enums\Placeholder;
use App\Enums\PromptSlot;
use App\Enums\StepAction;
use App\Enums\StepType;
use App\Models\AudioClip;
use App\Models\CallFlow;
use App\Models\FlowStep;
use App\Models\Language;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Builds version 1 of the IVR flow: the original call, step for step. Its
 * clips' wording and audio come from the committed bundle in
 * database/seeders/data/voice-prompts (manifest.json plus audio files).
 */
class DefaultCallFlow
{
    private CallFlow $flow;

    /**
     * @var array<string, AudioClip>
     */
    private array $clips = [];

    /**
     * Wording and audio per language code and clip key.
     *
     * @var array<string, array<string, array{text?: string, audio?: string|null, telephony_audio?: string|null}>>
     */
    private array $manifest = [];

    public function __construct(private ClipRecordingStore $recordingStore) {}

    public function create(): CallFlow
    {
        return DB::transaction(function (): CallFlow {
            $this->clips = [];
            $this->manifest = $this->readManifest();
            $this->flow = CallFlow::query()->create([
                'version' => (int) CallFlow::query()->max('version') + 1,
                'status' => FlowStatus::Draft,
                'settings' => CallFlow::DefaultSettings,
            ]);

            foreach (['not_understood', 'too_many_attempts', 'speech_unavailable', 'goodbye'] as $key) {
                $this->clip($key);
            }

            foreach (LoanApplicationStatus::cases() as $status) {
                $this->clip($status->promptKey());
            }

            $this->buildSteps();

            return $this->flow;
        });
    }

    private function buildSteps(): void
    {
        [$languageSaved, $otherwise] = $this->check(null, 'Language saved?', CheckCondition::LanguageSaved);
        $this->step($otherwise, StepType::ChooseLanguage, 'Choose language', ['choose_language']);

        [$known, $unknown] = $this->check(null, 'Known caller?', CheckCondition::KnownCaller);
        $this->step($known, StepType::Message, 'Greeting', ['greeting', Placeholder::CustomerName->value]);

        [$signUp, $declined] = $this->yesNo($unknown, 'Sign up?', ['offer_registration'], ['yes_no_hint', 'offer_registration']);
        $askName = $this->step($signUp, StepType::AskSpeech, 'Ask name', ['ask_name'], ['not_understood', 'ask_name'], ['variable' => 'name']);
        [$nameRight, $nameWrong] = $this->yesNo($signUp, 'Confirm name', ['confirm_name', 'answer:name', 'confirm_name_question'], ['yes_no_hint', 'confirm_name_question']);
        $this->step($nameRight, StepType::Do, 'Register caller', config: ['action' => StepAction::RegisterCaller->value]);
        $this->step($nameRight, StepType::Message, 'Registered', ['registered']);
        $this->goTo($nameWrong, 'Ask the name again', $askName);
        $this->step($declined, StepType::Message, 'Goodbye', ['goodbye']);
        $this->step($declined, StepType::EndCall, 'End call');

        $menu = $this->step(null, StepType::Choice, 'Main menu', ['main_menu'], ['not_understood', 'main_menu']);

        $balance = $this->option($menu, 'Balance', '1', 'check_balance');
        $this->step($balance, StepType::Message, 'Balance', ['balance_is', Placeholder::Balance->value]);

        $loan = $this->option($menu, 'Apply for a loan', '2', 'apply_loan');
        $this->step($loan, StepType::AskNumber, 'Loan amount', ['ask_amount'], ['amount_not_understood', 'ask_amount'], [
            'variable' => 'amount', 'unit' => NumberUnit::Cedis->value, 'min' => (int) config('conversation.loan.min_amount_cedis'), 'max' => (int) config('conversation.loan.max_amount_cedis'),
        ], ['amount_out_of_range']);
        $this->step($loan, StepType::AskNumber, 'Loan term (months)', ['ask_term'], ['term_invalid', 'ask_term'], [
            'variable' => 'months', 'unit' => NumberUnit::Months->value, 'min' => 1, 'max' => (int) config('conversation.loan.max_term_months'),
        ], ['term_invalid']);
        [$confirmed, $cancelled] = $this->yesNo($loan, 'Confirm application', ['confirm_application', 'answer:amount', 'answer:months', 'confirm_question'], ['yes_no_hint', 'confirm_question']);
        $this->step($confirmed, StepType::Do, 'Submit application', config: ['action' => StepAction::SubmitLoanApplication->value]);
        $this->step($confirmed, StepType::Message, 'Application submitted', ['application_submitted', Placeholder::ApplicationReference->value]);
        $this->step($cancelled, StepType::Message, 'Application cancelled', ['application_cancelled']);
        $this->goTo($cancelled, 'Back to main menu', $menu);

        $status = $this->option($menu, 'Application status', '3', 'application_status');
        [$hasApplication, $noApplication] = $this->check($status, 'Has an application?', CheckCondition::HasApplication);
        $this->step($hasApplication, StepType::Message, 'Application status', ['application_status_intro', Placeholder::ApplicationAmount->value, Placeholder::ApplicationReference->value, Placeholder::ApplicationStatus->value]);
        $this->step($noApplication, StepType::Message, 'No application', ['no_application']);

        $repayment = $this->option($menu, 'Repayment due', '4', 'repayment_due');
        [$due, $notDue] = $this->check($repayment, 'Has a repayment due?', CheckCondition::HasRepaymentDue);
        $this->step($due, StepType::Message, 'Repayment due', ['repayment_due_is', Placeholder::RepaymentAmount->value, 'repayment_due_on', Placeholder::RepaymentDate->value]);
        $this->step($notDue, StepType::Message, 'No repayment due', ['no_repayment_due']);

        $agent = $this->option($menu, 'Speak to an agent', '8', 'speak_to_agent');
        $this->step($agent, StepType::Transfer, 'Agent (not available yet)', ['agent_unavailable']);
        $this->goTo($agent, 'Back to main menu', $menu);

        $repeat = $this->option($menu, 'Repeat', '9', 'repeat');
        $this->goTo($repeat, 'Repeat the menu', $menu);

        $end = $this->option($menu, 'End call', '0', 'goodbye');
        $this->step($end, StepType::Message, 'Goodbye', ['goodbye']);
        $this->step($end, StepType::EndCall, 'End call');

        $this->otherwise($menu);

        [$more, $done] = $this->yesNo(null, 'Anything else?', ['anything_else'], ['yes_no_hint', 'anything_else']);
        $this->goTo($more, 'Back to main menu', $menu);
        $this->step($done, StepType::Message, 'Goodbye', ['goodbye']);
        $this->step($done, StepType::EndCall, 'End call');
    }

    /**
     * Add a step at the end of a list. Pieces are clip keys, placeholders
     * (see Placeholder) or saved answers ("answer:name").
     *
     * @param  list<string>  $prompt
     * @param  list<string>  $reprompt
     * @param  array<string, mixed>  $config
     * @param  list<string>  $outOfRange
     */
    private function step(?FlowStep $parent, StepType $type, string $name, array $prompt = [], array $reprompt = [], array $config = [], array $outOfRange = []): FlowStep
    {
        $step = $this->flow->steps()->create([
            'parent_id' => $parent?->id,
            'position' => $this->flow->steps()->where('parent_id', $parent?->id)->count(),
            'type' => $type,
            'name' => $name,
            'config' => $config,
        ]);

        foreach ([PromptSlot::Prompt->value => $prompt, PromptSlot::Reprompt->value => $reprompt, PromptSlot::OutOfRange->value => $outOfRange] as $slot => $pieces) {
            foreach ($pieces as $position => $piece) {
                $isValue = Placeholder::tryFrom($piece) !== null || str_starts_with($piece, 'answer:');

                $step->promptItems()->create([
                    'slot' => $slot,
                    'position' => $position,
                    'audio_clip_id' => $isValue ? null : $this->clip($piece)->id,
                    'placeholder' => $isValue ? $piece : null,
                ]);
            }
        }

        return $step;
    }

    /**
     * @return array{0: FlowStep, 1: FlowStep} The "yes" and "otherwise" branches
     */
    private function check(?FlowStep $parent, string $name, CheckCondition $condition): array
    {
        $check = $this->step($parent, StepType::Check, $name, config: ['condition' => $condition->value]);

        return [$this->branch($check, 'Yes'), $this->branch($check, 'Otherwise', isOtherwise: true)];
    }

    /**
     * A choice with Yes (key 1) and No (key 2) branches, matched by each
     * language's yes and no words.
     *
     * @param  list<string>  $prompt
     * @param  list<string>  $reprompt
     * @return array{0: FlowStep, 1: FlowStep}
     */
    private function yesNo(?FlowStep $parent, string $name, array $prompt, array $reprompt): array
    {
        $choice = $this->step($parent, StepType::Choice, $name, $prompt, $reprompt);
        $yes = $this->branch($choice, 'Yes', '1', ['match' => BranchMatch::Yes->value]);
        $no = $this->branch($choice, 'No', '2', ['match' => BranchMatch::No->value]);
        $this->otherwise($choice);

        return [$yes, $no];
    }

    /**
     * A menu option picked by its key or by the lexicon words of an intent.
     */
    private function option(FlowStep $menu, string $name, string $digit, string $intent): FlowStep
    {
        $branch = $this->branch($menu, $name, $digit, ['match' => BranchMatch::Keywords->value]);

        foreach (Language::withTrashed()->get() as $language) {
            $words = config("conversation.lexicon.{$language->code}.{$intent}", []);

            if ($words !== []) {
                $branch->keywords()->create(['language_id' => $language->id, 'words' => array_values($words)]);
            }
        }

        return $branch;
    }

    private function otherwise(FlowStep $choice): FlowStep
    {
        return $this->branch($choice, 'Otherwise', isOtherwise: true);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function branch(FlowStep $parent, string $name, ?string $digit = null, array $config = [], bool $isOtherwise = false): FlowStep
    {
        return $this->flow->steps()->create([
            'parent_id' => $parent->id,
            'position' => $parent->children()->count(),
            'type' => StepType::Branch,
            'name' => $name,
            'keypad_digit' => $digit,
            'config' => $config,
            'is_otherwise' => $isOtherwise,
        ]);
    }

    private function goTo(FlowStep $parent, string $name, FlowStep $target): FlowStep
    {
        $step = $this->step($parent, StepType::GoTo, $name);
        $step->update(['go_to_step_id' => $target->id]);

        return $step;
    }

    /**
     * The clip for a bundle key, created on first use with its wording and
     * audio in every language the bundle has.
     */
    private function clip(string $key): AudioClip
    {
        if (isset($this->clips[$key])) {
            return $this->clips[$key];
        }

        $clip = $this->flow->clips()->create(['key' => $key, 'group' => $this->groupOf($key)]);
        $bundlePath = (string) config('conversation.default_flow_bundle_path');

        foreach (Language::withTrashed()->get() as $language) {
            $entry = $this->manifest[$language->code][$key] ?? null;

            if ($entry === null) {
                continue;
            }

            $audio = $entry['audio'] ?? null;

            if ($audio !== null && File::exists("{$bundlePath}/{$audio}")) {
                $telephony = $entry['telephony_audio'] ?? null;

                $this->recordingStore->save(
                    $clip,
                    $language,
                    File::get("{$bundlePath}/{$audio}"),
                    $telephony !== null && File::exists("{$bundlePath}/{$telephony}") ? File::get("{$bundlePath}/{$telephony}") : null,
                    basename($audio),
                );
            }

            $clip->recordings()->updateOrCreate(['language_id' => $language->id], ['text' => $entry['text'] ?? null]);
        }

        return $this->clips[$key] = $clip;
    }

    private function groupOf(string $key): string
    {
        foreach (config('conversation.clip_groups') as $group => $keys) {
            if (in_array($key, $keys, true)) {
                return $group;
            }
        }

        return 'other';
    }

    /**
     * @return array<string, array<string, array{text?: string, audio?: string|null, telephony_audio?: string|null}>>
     */
    private function readManifest(): array
    {
        $path = config('conversation.default_flow_bundle_path').'/manifest.json';

        return File::exists($path) ? (array) json_decode(File::get($path), true) : [];
    }
}
