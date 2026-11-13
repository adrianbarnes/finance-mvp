<?php

use App\Enums\BranchMatch;
use App\Enums\PromptSlot;
use App\Enums\StepType;
use App\Livewire\CallFlows\Builder;
use App\Models\CallFlow;
use App\Models\FlowStep;
use App\Models\User;
use App\Services\Audio\AudioTranscoder;
use App\Services\Flows\FlowPublisher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    app(FlowPublisher::class)->draft();
});

/**
 * A step of the draft by its name; branches are found under their step.
 */
function flowStep(string $name, ?string $under = null): FlowStep
{
    return CallFlow::draft()->steps()
        ->where('name', $name)
        ->when($under, fn ($query) => $query->whereHas('parent', fn ($parent) => $parent->where('name', $under)))
        ->firstOrFail();
}

/**
 * @return list<string>
 */
function stepNames(?FlowStep $branch): array
{
    return CallFlow::draft()->steps()->where('parent_id', $branch?->id)->orderBy('position')->pluck('name')->all();
}

test('guests are redirected to the login page', function () {
    auth()->logout();

    $this->get(route('call-flows.builder'))->assertRedirect(route('login'));
});

test('publishes today\'s call as version 1 and opens a draft of it the first time', function () {
    CallFlow::query()->delete();

    $this->get(route('call-flows.builder'))->assertOk()->assertSee('Main menu');

    expect(CallFlow::query()->pluck('status')->map->value->all())->toBe(['published', 'draft'])
        ->and(stepNames(null))->toBe(['Language saved?', 'Known caller?', 'Main menu', 'Anything else?'])
        ->and(stepNames(flowStep('Apply for a loan')))->toBe(['Loan amount', 'Loan term (months)', 'Confirm application']);

    $this->get(route('call-flows.builder'))->assertOk();

    expect(CallFlow::query()->count())->toBe(2);
});

test('adds a step at a chosen place inside a branch, with a recording piece to fill', function () {
    $component = Livewire::test(Builder::class);
    $balance = flowStep('Balance', 'Main menu');

    $component->call('addStep', $balance->id, 0, StepType::Message->value)->assertHasNoErrors();

    $added = CallFlow::draft()->steps()->where('parent_id', $balance->id)->orderBy('position')->first();
    expect(stepNames($balance))->toBe(['Play message', 'Balance'])
        ->and($added->promptItemsFor(PromptSlot::Prompt))->toHaveCount(1)
        ->and($component->get('selectedStepId'))->toBe($added->id);
});

test('adds a yes/no question with Yes, No and Otherwise branches', function () {
    Livewire::test(Builder::class)->call('addStep', null, 0, 'yes_no');

    $question = CallFlow::draft()->steps()->whereNull('parent_id')->orderBy('position')->first();
    $branches = $question->children()->get();

    expect($question->type)->toBe(StepType::Choice)
        ->and($branches->pluck('name')->all())->toBe(['Yes', 'No', 'Otherwise'])
        ->and($branches->pluck('keypad_digit')->all())->toBe(['1', '2', null])
        ->and($branches->first()->configValue('match'))->toBe(BranchMatch::Yes->value);
});

test('adds an option to a menu with the next free key, before Otherwise', function () {
    Livewire::test(Builder::class)->call('addBranch', flowStep('Main menu')->id);

    $branches = flowStep('Main menu')->children()->get();

    expect($branches->pluck('keypad_digit')->reject(fn ($digit) => $digit === null)->values()->all())->toBe(['1', '2', '3', '4', '8', '9', '0', '5'])
        ->and($branches->last()->is_otherwise)->toBeTrue();
});

test('rejects adding steps directly under a choice', function () {
    Livewire::test(Builder::class)->call('addStep', flowStep('Main menu')->id, 0, StepType::Message->value)->assertStatus(422);
});

test('moves a step into another branch by dragging', function () {
    $component = Livewire::test(Builder::class);
    $balanceMessage = flowStep('Balance', 'Balance');
    $statusBranch = flowStep('Application status', 'Main menu');

    $component->call('moveStep', $balanceMessage->id, 1, (string) $statusBranch->id);

    expect(stepNames($statusBranch))->toBe(['Has an application?', 'Balance'])
        ->and(stepNames(flowStep('Balance', 'Main menu')))->toBe([]);
});

test('reorders steps at the top level', function () {
    Livewire::test(Builder::class)->call('moveStep', flowStep('Anything else?')->id, 0, 'root');

    expect(stepNames(null))->toBe(['Anything else?', 'Language saved?', 'Known caller?', 'Main menu']);
});

test('refuses to move a step inside itself', function () {
    $component = Livewire::test(Builder::class);
    $menu = flowStep('Main menu');

    $component->call('moveStep', $menu->id, 0, (string) flowStep('Balance', 'Main menu')->id);

    expect($menu->fresh()->parent_id)->toBeNull();
});

test('reorders the options of a menu', function () {
    Livewire::test(Builder::class)->call('moveBranch', flowStep('End call', 'Main menu')->id, 0);

    expect(stepNames(flowStep('Main menu'))[0])->toBe('End call');
});

test('deletes a step with everything inside it', function () {
    $component = Livewire::test(Builder::class);
    $loan = flowStep('Apply for a loan', 'Main menu');

    $component->call('deleteStep', $loan->id);

    expect(FlowStep::query()->whereKey($loan->id)->exists())->toBeFalse()
        ->and(CallFlow::draft()->steps()->where('name', 'Loan amount')->exists())->toBeFalse();
});

test('keeps the branches a step depends on', function () {
    $component = Livewire::test(Builder::class);
    $otherwise = flowStep('Otherwise', 'Main menu');

    $component->call('deleteStep', $otherwise->id);

    expect($otherwise->fresh())->not->toBeNull();
});

test('saves an option\'s key and its words for the selected language only', function () {
    $balance = flowStep('Balance', 'Main menu');

    Livewire::test(Builder::class)
        ->set('languageCode', 'ewe')
        ->call('selectStep', $balance->id)
        ->set('keypadDigit', '7')
        ->set('keywords', 'ga, Nye ga')
        ->call('saveStep')
        ->assertHasNoErrors();

    $balance->refresh()->load('keywords');
    expect($balance->keypad_digit)->toBe('7')
        ->and($balance->keywordsFor(language('ewe')))->toBe(['ga', 'nye ga'])
        ->and($balance->keywordsFor(language('twi')))->toContain('sika dodoo');
});

test('rejects a key another option already uses', function () {
    Livewire::test(Builder::class)
        ->call('selectStep', flowStep('Balance', 'Main menu')->id)
        ->set('keypadDigit', '2')
        ->call('saveStep')
        ->assertHasErrors('keypadDigit');
});

test('saves the range of a number question', function () {
    $amount = flowStep('Loan amount');

    Livewire::test(Builder::class)
        ->call('selectStep', $amount->id)
        ->set('min', '50')
        ->set('max', '20')
        ->call('saveStep')
        ->assertHasErrors('max')
        ->set('max', '5000')
        ->call('saveStep')
        ->assertHasNoErrors();

    expect($amount->fresh()->config)->toMatchArray(['variable' => 'amount', 'min' => 50, 'max' => 5000]);
});

test('points a go-to step at another step', function () {
    $goTo = flowStep('Back to main menu', 'No');

    Livewire::test(Builder::class)
        ->call('selectStep', $goTo->id)
        ->set('goToStepId', (string) flowStep('Anything else?')->id)
        ->call('saveStep')
        ->assertHasNoErrors();

    expect($goTo->fresh()->go_to_step_id)->toBe(flowStep('Anything else?')->id);
});

test('uploads a recording for the selected language', function () {
    Storage::fake('public');
    $this->mock(AudioTranscoder::class, function ($mock) {
        $mock->shouldReceive('forBrowserPlayback')->andReturn('mp3-bytes');
        $mock->shouldReceive('forTelephony')->andReturn(str_repeat('w', 44 + 16_000));
    });
    $greeting = flowStep('Greeting');
    $clip = $greeting->promptItems()->first()->clip;

    Livewire::test(Builder::class)
        ->set('languageCode', 'ewe')
        ->call('selectStep', $greeting->id)
        ->set('uploads.'.$clip->id, UploadedFile::fake()->createWithContent('woezo.wav', 'RIFF-audio')->mimeType('audio/wav'))
        ->assertHasNoErrors();

    $recording = $clip->recordings()->where('language_id', language('ewe')->id)->sole();
    expect($recording->audio_path)->toBe('flow-audio/'.sha1('mp3-bytes').'.mp3')
        ->and($recording->original_filename)->toBe('woezo.wav')
        ->and($recording->duration_ms)->toBe(1000);
    Storage::disk('public')->assertExists($recording->audio_path);
});

test('rejects an upload that is not audio', function () {
    $clip = flowStep('Greeting')->promptItems()->first()->clip;

    Livewire::test(Builder::class)
        ->set('uploads.'.$clip->id, UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
        ->assertHasErrors('uploads.'.$clip->id);
});

test('removes a recording but keeps its wording', function () {
    $clip = flowStep('Greeting')->promptItems()->first()->clip;
    $clip->recordings()->updateOrCreate(['language_id' => language('ewe')->id], ['text' => 'Woezɔ', 'audio_path' => 'flow-audio/woezo.mp3']);

    Livewire::test(Builder::class)->set('languageCode', 'ewe')->call('removeRecording', $clip->id);

    $recording = $clip->recordings()->where('language_id', language('ewe')->id)->sole();
    expect($recording->audio_path)->toBeNull()->and($recording->text)->toBe('Woezɔ');
});

test('saves the wording of a recording in the selected language', function () {
    $clip = flowStep('Greeting')->promptItems()->first()->clip;

    Livewire::test(Builder::class)->set('languageCode', 'ewe')->set('recordingTexts.'.$clip->id, ' Woezɔ ');

    expect($clip->recordings()->where('language_id', language('ewe')->id)->value('text'))->toBe('Woezɔ');
});

test('builds what a step says from recordings, values and saved answers', function () {
    $component = Livewire::test(Builder::class);
    $message = flowStep('Registered');
    $component->call('selectStep', $message->id)
        ->call('addPiece', 'prompt', 'value:customer_name')
        ->call('addPiece', 'prompt', 'answer:name')
        ->call('addPiece', 'prompt', 'new');

    $pieces = $message->fresh()->promptItemsFor(PromptSlot::Prompt);
    expect($pieces->pluck('placeholder')->all())->toBe([null, 'customer_name', 'answer:name', null]);

    $component->call('movePiece', $pieces[1]->id, -1)->call('removePiece', $pieces[3]->id);

    expect($message->fresh()->promptItemsFor(PromptSlot::Prompt)->pluck('placeholder')->all())->toBe(['customer_name', null, 'answer:name']);
});

test('rejects pieces a step cannot have', function (string $slot, string $piece) {
    Livewire::test(Builder::class)
        ->call('selectStep', flowStep('Registered')->id)
        ->call('addPiece', $slot, $piece)
        ->assertStatus(422);
})->with([
    'a reprompt on a message' => ['reprompt', 'new'],
    'an answer no step saves' => ['prompt', 'answer:shoe_size'],
]);

test('saves the flow settings', function () {
    Livewire::test(Builder::class)
        ->set('maxNoInputReplays', '1')
        ->set('maxMisunderstoodAttempts', '4')
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect(CallFlow::draft()->setting('max_no_input_replays'))->toBe(1)
        ->and(CallFlow::draft()->setting('max_misunderstood_attempts'))->toBe(4);
});

test('starts with nested branches folded, and folds or unfolds them on request', function () {
    $signUp = flowStep('Sign up?');
    $menu = flowStep('Main menu');

    $component = Livewire::test(Builder::class);

    expect($component->get('collapsedStepIds'))->toContain($signUp->id)->not->toContain($menu->id);

    $component->call('toggleBranches', $signUp->id)->assertSee('Ask name');
    expect($component->get('collapsedStepIds'))->not->toContain($signUp->id);

    $component->call('toggleBranches', $menu->id)->assertDontSee('Speak to an agent');
    $component->call('expandAll')->assertSet('collapsedStepIds', []);
});

test('publishes the draft from the builder and carries on with a new draft', function () {
    $component = Livewire::test(Builder::class);
    $version = CallFlow::draft()->version;

    $component->call('publish')->assertSet('publishProblems', []);

    expect(app(FlowPublisher::class)->live()->version)->toBe($version)
        ->and(CallFlow::draft()->version)->toBe($version + 1);
});

test('lists the problems that stop the draft being published', function () {
    $component = Livewire::test(Builder::class)->call('addStep', null, 0, StepType::Message->value);

    $component->call('publish')
        ->assertSet('publishProblems', ['"Play message" has no recording in any language.'])
        ->assertSee('The draft was not published');

    expect(app(FlowPublisher::class)->live()->version)->toBe(1);
});

test('discards the draft\'s changes', function () {
    $component = Livewire::test(Builder::class)->call('deleteStep', flowStep('Anything else?')->id);

    $component->call('discardDraft');

    expect(stepNames(null))->toContain('Anything else?');
});

test('lists the versions and copies an earlier one into the draft', function () {
    $publisher = app(FlowPublisher::class);
    $draft = CallFlow::draft();
    $draft->steps()->where('name', 'Main menu')->update(['name' => 'Menu v2']);
    $publisher->publish($draft);

    $component = Livewire::test(Builder::class)
        ->set('tab', 'versions')
        ->assertSee(['v3', 'v2', 'v1', 'Live', 'Archived']);

    $component->call('restoreVersion', CallFlow::query()->where('version', 1)->value('id'))
        ->assertSet('tab', 'flow');

    expect(stepNames(null))->toContain('Main menu');
});

test('publishes an archived version again from the versions tab', function () {
    $publisher = app(FlowPublisher::class);
    $publisher->publish(CallFlow::draft());

    Livewire::test(Builder::class)->call('republishVersion', CallFlow::query()->where('version', 1)->value('id'))->assertSet('publishProblems', []);

    expect($publisher->live()->version)->toBe(4);
});

test('purges archived versions from the versions tab', function () {
    app(FlowPublisher::class)->publish(CallFlow::draft());

    Livewire::test(Builder::class)->call('purgeArchived');

    expect(CallFlow::query()->where('version', 1)->exists())->toBeFalse()
        ->and(CallFlow::query()->count())->toBe(2);
});
