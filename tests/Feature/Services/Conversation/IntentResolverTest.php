<?php

use App\Enums\Intent;
use App\Enums\Language;
use App\Services\Conversation\IntentResolver;

test('recognises menu requests in Twi and English', function (string $transcript, Intent $intent) {
    expect(app(IntentResolver::class)->resolve($transcript, Language::Twi))->toBe($intent);
})->with([
    'balance in Twi' => ['Me pɛ sɛ mehwɛ me sika dodoɔ', Intent::CheckBalance],
    'balance in English' => ['what is my account balance', Intent::CheckBalance],
    'loan in Twi' => ['me pɛ bosea', Intent::ApplyLoan],
    'status mentions loan too' => ['me bosea abisadeɛ no aduru he', Intent::ApplicationStatus],
    'repayment mentions loan too' => ['bere bɛn na metua me bosea', Intent::RepaymentDue],
    'agent' => ['I want to speak to a real person', Intent::SpeakToAgent],
    'nothing recognisable' => ['hmm', Intent::Unknown],
]);

test('treats "no" as an answer only when it is the whole utterance', function () {
    $resolver = app(IntentResolver::class);

    expect($resolver->resolve('No', Language::Twi, expectsConfirmation: true))->toBe(Intent::No)
        ->and($resolver->resolve('bosea no', Language::Twi, expectsConfirmation: true))->toBe(Intent::ApplyLoan);
});

test('finds the language the caller accepted on the language prompt', function (string $transcript, ?Language $language) {
    expect(app(IntentResolver::class)->chosenLanguage($transcript))->toBe($language);
})->with([
    'Twi yes' => ['Aane', Language::Twi],
    'language name' => ['Twi', Language::Twi],
    'Ewe yes' => ['ɛ̃', Language::Ewe],
    'English yes' => ['yes please', Language::English],
    'Fante yes while Fante is disabled' => ['nyew', null],
    'no' => ['daabi', null],
]);

test('numbers the languages on the keypad in the order they are enabled', function () {
    $resolver = app(IntentResolver::class);

    expect($resolver->chosenLanguageByKeypad('1'))->toBe(Language::Twi)
        ->and($resolver->chosenLanguageByKeypad('2'))->toBe(Language::Ewe)
        ->and($resolver->chosenLanguageByKeypad('3'))->toBe(Language::English)
        ->and($resolver->chosenLanguageByKeypad('4'))->toBeNull();
});

test('recognises Ewe menu requests', function (string $transcript, Intent $intent) {
    expect(app(IntentResolver::class)->resolve($transcript, Language::Ewe))->toBe($intent);
})->with([
    'loan' => ['medi fenyinyi', Intent::ApplyLoan],
    'balance' => ['nye ga nenie le akɔnta me', Intent::CheckBalance],
]);

test('accepts other languages once they are enabled', function () {
    config(['conversation.enabled_languages' => ['twi', 'fat']]);

    expect(app(IntentResolver::class)->chosenLanguage('Nyew'))->toBe(Language::Fante)
        ->and(app(IntentResolver::class)->chosenLanguageByKeypad('2'))->toBe(Language::Fante);
});
