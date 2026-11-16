<?php

use App\Models\CallFlow;
use App\Models\FlowStep;
use App\Services\Conversation\IntentResolver;
use App\Services\Flows\DefaultCallFlow;
use Illuminate\Support\Collection;

beforeEach(function () {
    app(DefaultCallFlow::class)->create();
});

/**
 * The branches of a choice in version 1 of the flow, keywords loaded.
 *
 * @return Collection<int, FlowStep>
 */
function branchesOf(string $choiceName): Collection
{
    return CallFlow::draft()->steps()->where('name', $choiceName)->firstOrFail()
        ->children()->with('keywords')->get();
}

test('picks the main menu option from words in Twi and English', function (string $transcript, ?string $option) {
    expect(app(IntentResolver::class)->matchBranch(branchesOf('Main menu'), $transcript, language('twi'))?->name)->toBe($option);
})->with([
    'balance in Twi' => ['Me pɛ sɛ mehwɛ me sika dodoɔ', 'Balance'],
    'balance in English' => ['what is my account balance', 'Balance'],
    'loan in Twi' => ['me pɛ bosea', 'Apply for a loan'],
    'status mentions loan too' => ['me bosea abisadeɛ no aduru he', 'Application status'],
    'repayment mentions loan too' => ['bere bɛn na metua me bosea', 'Repayment due'],
    'agent' => ['I want to speak to a real person', 'Speak to an agent'],
    'nothing recognisable' => ['hmm', null],
]);

test('picks Ewe menu options from the Ewe words', function (string $transcript, string $option) {
    expect(app(IntentResolver::class)->matchBranch(branchesOf('Main menu'), $transcript, language('ewe'))?->name)->toBe($option);
})->with([
    'loan' => ['medi fenyinyi', 'Apply for a loan'],
    'balance' => ['nye ga nenie le akɔnta me', 'Balance'],
]);

test('answers yes/no questions with the language\'s yes and no words', function () {
    $resolver = app(IntentResolver::class);
    $branches = branchesOf('Anything else?');

    expect($resolver->matchBranch($branches, 'Aane', language('twi'))?->name)->toBe('Yes')
        ->and($resolver->matchBranch($branches, 'No', language('twi'))?->name)->toBe('No')
        ->and($resolver->matchBranch($branches, 'bosea no', language('twi')))->toBeNull();
});

test('uses the yes and no words saved for the language', function () {
    language('twi')->update(['yes_words' => ['nsɛm pa'], 'no_words' => ['=gyae']]);
    $resolver = app(IntentResolver::class);
    $branches = branchesOf('Anything else?');

    expect($resolver->matchBranch($branches, 'nsɛm pa', language('twi'))?->name)->toBe('Yes')
        ->and($resolver->matchBranch($branches, 'gyae', language('twi'))?->name)->toBe('No')
        ->and($resolver->matchBranch($branches, 'aane', language('twi')))->toBeNull();
});

test('picks an option by its key, ignoring a trailing hash', function () {
    $resolver = app(IntentResolver::class);

    expect($resolver->branchByKeypad(branchesOf('Main menu'), '4')?->name)->toBe('Repayment due')
        ->and($resolver->branchByKeypad(branchesOf('Main menu'), '0#')?->name)->toBe('End call')
        ->and($resolver->branchByKeypad(branchesOf('Main menu'), '7'))->toBeNull();
});

test('finds the language the caller accepted on the language prompt', function (string $transcript, ?string $code) {
    expect(app(IntentResolver::class)->chosenLanguage($transcript)?->code)->toBe($code);
})->with([
    'Twi yes' => ['Aane', 'twi'],
    'language name' => ['Twi', 'twi'],
    'Ewe yes' => ['ɛ̃', 'ewe'],
    'English yes' => ['yes please', 'eng'],
    'Fante yes while Fante is disabled' => ['nyew', null],
    'no' => ['daabi', null],
]);

test('accepts the language from an answer that sounds like its word when Khaya misspells it', function (string $transcript, ?string $code) {
    expect(app(IntentResolver::class)->chosenLanguage($transcript)?->code)->toBe($code);
})->with([
    'aane heard as anni' => ['Anni', 'twi'],
    'aane heard as a ɛnne' => ['a ɛnne', 'twi'],
    'aane heard as baani' => ['baani', 'twi'],
    'English heard as inglish' => ['inglish', 'eng'],
    'nothing like any language word' => ['I need', null],
    'one sound off inside a longer answer is not enough for a short word' => ['me pɛ anni bosea', null],
]);

test('does not guess when an answer sounds like more than one option', function () {
    language('twi')->update(['selection_words' => ['aane']]);
    language('ewe')->update(['selection_words' => ['aana']]);

    expect(app(IntentResolver::class)->chosenLanguage('aani'))->toBeNull()
        ->and(app(IntentResolver::class)->chosenLanguage('aane')?->code)->toBe('twi');
});

test('answers yes/no from a misspelled yes or no, and asks again when it could be either', function () {
    $resolver = app(IntentResolver::class);
    $branches = branchesOf('Anything else?');

    expect($resolver->matchBranch($branches, 'anni', language('twi'))?->name)->toBe('Yes')
        ->and($resolver->matchBranch($branches, 'daabe', language('twi'))?->name)->toBe('No')
        ->and($resolver->matchBranch($branches, 'mmm', language('twi')))->toBeNull();

    language('twi')->update(['yes_words' => ['aane'], 'no_words' => ['aana']]);

    expect($resolver->matchBranch($branches, 'aani', language('twi')->fresh()))->toBeNull();
});

test('picks a menu option from a long word said slightly differently', function () {
    expect(app(IntentResolver::class)->matchBranch(branchesOf('Main menu'), 'mepɛ sɛ mehwɛ me sika dodo', language('twi'))?->name)->toBe('Balance')
        ->and(app(IntentResolver::class)->matchBranch(branchesOf('Main menu'), 'my akaunt', language('twi'))?->name)->toBe('Balance');
});

test('numbers the languages on the keypad in the order they are enabled', function () {
    $resolver = app(IntentResolver::class);

    expect($resolver->chosenLanguageByKeypad('1')?->code)->toBe('twi')
        ->and($resolver->chosenLanguageByKeypad('2')?->code)->toBe('ewe')
        ->and($resolver->chosenLanguageByKeypad('3')?->code)->toBe('eng')
        ->and($resolver->chosenLanguageByKeypad('4'))->toBeNull();
});

test('accepts other languages once they are switched on, numbered in their order', function () {
    language('ewe')->update(['enabled' => false]);
    language('eng')->update(['enabled' => false]);
    language('fat')->update(['enabled' => true]);

    expect(app(IntentResolver::class)->chosenLanguage('Nyew')?->code)->toBe('fat')
        ->and(app(IntentResolver::class)->chosenLanguageByKeypad('2')?->code)->toBe('fat');
});

test('ignores deleted languages on the language prompt', function () {
    language('ewe')->delete();

    expect(app(IntentResolver::class)->chosenLanguage('ɛ̃'))->toBeNull()
        ->and(app(IntentResolver::class)->chosenLanguageByKeypad('2')?->code)->toBe('eng');
});
