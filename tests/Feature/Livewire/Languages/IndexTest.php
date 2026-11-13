<?php

use App\Enums\SpeechRecognition;
use App\Livewire\Languages\Index;
use App\Models\Language;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('guests are redirected to the login page', function () {
    auth()->logout();

    $this->get(route('languages.index'))->assertRedirect(route('login'));
});

test('lists the languages in their order', function () {
    $this->get(route('languages.index'))
        ->assertOk()
        ->assertSeeInOrder(['Twi', 'Ewe', 'English', 'Fante', 'Ga']);
});

test('adds a language switched off at the end of the list', function () {
    Livewire::test(Index::class)
        ->call('create')
        ->set('name', 'Dagbani')
        ->set('code', ' DAG ')
        ->set('speechRecognition', SpeechRecognition::Khaya->value)
        ->set('khayaCode', 'dag')
        ->set('selectionWords', "Iin,\n dagbani, iin")
        ->set('yesWords', 'iin')
        ->set('noWords', 'ayi')
        ->set('numberWords', "yini = 1\nayi = 2")
        ->call('save')
        ->assertHasNoErrors();

    $language = Language::findByCode('dag');

    expect($language->name)->toBe('Dagbani')
        ->and($language->enabled)->toBeFalse()
        ->and($language->position)->toBe(6)
        ->and($language->khaya_code)->toBe('dag')
        ->and($language->selection_words)->toBe(['iin', 'dagbani'])
        ->and($language->number_words)->toBe(['yini' => 1, 'ayi' => 2]);
});

test('rejects invalid language details', function (array $input, string $field) {
    Livewire::test(Index::class)
        ->call('create')
        ->set(['name' => 'Dagbani', 'code' => 'dag', 'speechRecognition' => 'keypad', ...$input])
        ->call('save')
        ->assertHasErrors($field);

    expect(Language::findByCode('dag'))->toBeNull();
})->with([
    'code already used' => [['code' => 'twi'], 'code'],
    'code not an ISO code' => [['code' => 'dag-1'], 'code'],
    'Khaya without its code' => [['speechRecognition' => 'khaya', 'khayaCode' => ''], 'khayaCode'],
    'number word without a number' => [['numberWords' => 'yini one'], 'numberWords'],
]);

test('points to restoring when the code belongs to a deleted language', function () {
    language('gaa')->delete();

    Livewire::test(Index::class)
        ->call('create')
        ->set(['name' => 'Ga again', 'code' => 'gaa', 'speechRecognition' => 'keypad'])
        ->call('save')
        ->assertHasErrors(['code' => 'A deleted language uses this code. Restore it under Deleted languages instead.']);
});

test('edits a language without changing its code', function () {
    Livewire::test(Index::class)
        ->call('edit', language('ewe')->id)
        ->assertSet('selectionWords', 'ẽ, ɛ̃, ee, ewe, eʋegbe, evegbe')
        ->set('code', 'xyz')
        ->set('name', 'Eʋegbe')
        ->set('yesWords', 'ẽ, yoo')
        ->call('save')
        ->assertHasNoErrors();

    expect(language('ewe')->name)->toBe('Eʋegbe')
        ->and(language('ewe')->yes_words)->toBe(['ẽ', 'yoo'])
        ->and(Language::findByCode('xyz'))->toBeNull();
});

test('switches a language off and on again', function () {
    $component = Livewire::test(Index::class)->call('toggleEnabled', language('ewe')->id);

    expect(language('ewe')->enabled)->toBeFalse();

    $component->call('toggleEnabled', language('ewe')->id);

    expect(language('ewe')->enabled)->toBeTrue();
});

test('keeps the primary language switched on and undeleted', function (string $action) {
    Livewire::test(Index::class)->call($action, language('twi')->id);

    expect(language('twi')->enabled)->toBeTrue()
        ->and(language('twi')->trashed())->toBeFalse();
})->with(['toggleEnabled', 'delete']);

test('makes another language primary, switching it on', function () {
    Livewire::test(Index::class)->call('makePrimary', language('fat')->id);

    expect(Language::primary()->code)->toBe('fat')
        ->and(language('fat')->enabled)->toBeTrue()
        ->and(Language::query()->where('is_primary', true)->count())->toBe(1);
});

test('reorders languages by dragging, which changes their keys', function () {
    Livewire::test(Index::class)->call('sort', language('eng')->id, 0);

    expect(Language::query()->ordered()->pluck('code')->all())->toBe(['eng', 'twi', 'ewe', 'fat', 'gaa'])
        ->and(language('eng')->keypadNumber())->toBe('1')
        ->and(language('ewe')->keypadNumber())->toBe('3');
});

test('soft deletes a language and restores it with its words', function () {
    $ewe = language('ewe');

    Livewire::test(Index::class)->call('delete', $ewe->id);

    expect(Language::findByCode('ewe'))->toBeNull();
    $this->assertSoftDeleted('languages', ['id' => $ewe->id]);

    Livewire::test(Index::class)
        ->assertSee('Deleted languages')
        ->call('restore', $ewe->id);

    expect(language('ewe')->yes_words)->toContain('ẽ');
});
