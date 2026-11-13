<?php

use App\Enums\FlowStatus;
use App\Enums\StepType;
use App\Models\CallFlow;
use App\Models\CallSession;
use App\Models\FlowStep;
use App\Services\Flows\FlowEditor;
use App\Services\Flows\FlowPublisher;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->publisher = app(FlowPublisher::class);
    $this->live = $this->publisher->live();
    $this->draft = $this->publisher->draft();
});

function draftStep(CallFlow $flow, string $name): FlowStep
{
    return $flow->steps()->where('name', $name)->firstOrFail();
}

test('publishes today\'s call as version 1 on a fresh install', function () {
    expect($this->live->version)->toBe(1)
        ->and($this->live->status)->toBe(FlowStatus::Published)
        ->and($this->draft->version)->toBe(2)
        ->and($this->draft->steps()->count())->toBe($this->live->steps()->count());
});

test('copies a version into a draft that changes independently', function () {
    $liveGoTo = draftStep($this->live, 'Repeat the menu');
    $draftGoTo = draftStep($this->draft, 'Repeat the menu');

    expect($draftGoTo->goToStep->call_flow_id)->toBe($this->draft->id)
        ->and($draftGoTo->goToStep->name)->toBe('Main menu')
        ->and($liveGoTo->goToStep->call_flow_id)->toBe($this->live->id);

    $draftClip = $this->draft->clips()->where('key', 'greeting')->sole();
    $liveClip = $this->live->clips()->where('key', 'greeting')->sole();
    $draftClip->recordings()->where('language_id', language('twi')->id)->update(['audio_path' => 'flow-audio/new-take.mp3']);

    expect($liveClip->recordings()->where('language_id', language('twi')->id)->value('audio_path'))->not->toBe('flow-audio/new-take.mp3');
});

test('publishing makes the draft live, archives the old version and opens a new draft', function () {
    app(FlowEditor::class)->delete(draftStep($this->draft, 'Anything else?'));

    expect($this->publisher->publish($this->draft))->toBe([]);

    expect($this->live->fresh()->status)->toBe(FlowStatus::Archived)
        ->and($this->publisher->live()->is($this->draft))->toBeTrue()
        ->and(CallFlow::draft()->version)->toBe(3)
        ->and(CallFlow::draft()->steps()->where('name', 'Anything else?')->exists())->toBeFalse();
});

test('refuses to publish a draft with problems', function (Closure $breakDraft, string $problem) {
    $breakDraft($this->draft);

    $problems = $this->publisher->publish($this->draft);

    expect($problems)->toContain($problem)
        ->and($this->draft->fresh()->status)->toBe(FlowStatus::Draft)
        ->and($this->publisher->live()->is($this->live))->toBeTrue();
})->with([
    'a step with no recording' => [fn (CallFlow $draft) => app(FlowEditor::class)->addStep($draft, null, 0, StepType::Message->value), '"Play message" has no recording in any language.'],
    'two options with one key' => [fn (CallFlow $draft) => draftStep($draft, 'Repeat')->update(['keypad_digit' => '1']), '"Main menu" uses the same key for two options.'],
    'a go-to without a target' => [fn (CallFlow $draft) => draftStep($draft, 'Repeat the menu')->update(['go_to_step_id' => null]), '"Repeat the menu" does not say which step to go to.'],
]);

test('discarding the draft starts again from the live version', function () {
    app(FlowEditor::class)->delete(draftStep($this->draft, 'Anything else?'));

    $fresh = $this->publisher->discardDraft();

    expect(CallFlow::query()->where('status', FlowStatus::Draft)->count())->toBe(1)
        ->and($fresh->steps()->where('name', 'Anything else?')->exists())->toBeTrue();
});

/**
 * Publish the current draft after renaming its main menu, so version 1 is
 * archived and version 2 is live.
 */
function publishRenamedMenu(FlowPublisher $publisher, string $name): CallFlow
{
    $draft = $publisher->draft();
    draftStep($draft, 'Main menu')->update(['name' => $name]);
    $publisher->publish($draft);

    return $draft->fresh();
}

test('copies an earlier version into the draft, replacing the draft', function () {
    publishRenamedMenu($this->publisher, 'Menu v2');

    $draft = $this->publisher->restoreIntoDraft($this->live->fresh());

    expect(CallFlow::query()->where('status', FlowStatus::Draft)->count())->toBe(1)
        ->and(CallFlow::draft()->is($draft))->toBeTrue()
        ->and($draft->steps()->where('name', 'Main menu')->exists())->toBeTrue()
        ->and($this->publisher->live()->steps()->where('name', 'Menu v2')->exists())->toBeTrue();
});

test('refuses to copy the draft into itself', function () {
    $this->publisher->restoreIntoDraft($this->draft);
})->throws(HttpException::class);

test('publishes an archived version again as a new version, leaving the draft alone', function () {
    $liveV2 = publishRenamedMenu($this->publisher, 'Menu v2');
    $draft = CallFlow::draft();

    expect($this->publisher->republish($this->live->fresh()))->toBe([]);

    $live = $this->publisher->live();
    expect($live->version)->toBe(4)
        ->and($live->steps()->where('name', 'Main menu')->exists())->toBeTrue()
        ->and($liveV2->fresh()->status)->toBe(FlowStatus::Archived)
        ->and(CallFlow::draft()->is($draft))->toBeTrue();
});

test('only publishes archived versions again', function () {
    $this->publisher->republish($this->live);
})->throws(HttpException::class);

test('purges archived versions, keeping past calls and audio still in use', function () {
    Storage::disk('public')->put('flow-audio/only-in-v1.mp3', 'old take');
    $this->live->clips()->where('key', 'greeting')->sole()->recordings()->where('language_id', language('twi')->id)->update(['audio_path' => 'flow-audio/only-in-v1.mp3']);
    $sharedPath = $this->live->clips()->where('key', 'main_menu')->sole()->recordings()->where('language_id', language('twi')->id)->value('audio_path');
    $call = CallSession::factory()->create(['call_flow_id' => $this->live->id]);
    $call->turns()->create(['speaker' => 'assistant', 'text' => 'Akwaaba']);
    publishRenamedMenu($this->publisher, 'Menu v2');

    expect($this->publisher->purge())->toBe(1);

    expect(CallFlow::query()->whereKey($this->live->id)->exists())->toBeFalse()
        ->and(CallFlow::query()->pluck('status')->map->value->sort()->values()->all())->toBe(['draft', 'published'])
        ->and($call->fresh()->call_flow_id)->toBeNull()
        ->and($call->turns()->count())->toBe(1);
    Storage::disk('public')->assertMissing('flow-audio/only-in-v1.mp3');
    Storage::disk('public')->assertExists($sharedPath);
});

test('refuses to delete the live version or the draft', function () {
    $this->publisher->purge([$this->live->id]);
})->throws(HttpException::class);
