<?php

use App\Services\Conversation\NumberParser;

test('reads amounts and months from transcripts', function (string $transcript, ?float $number) {
    expect(app(NumberParser::class)->parse($transcript, language('twi')))->toBe($number);
})->with([
    'digits with comma' => ['GHS 1,500', 1500.0],
    'decimal digits' => ['250.50 cedis', 250.5],
    'English words' => ['one thousand five hundred cedis', 1500.0],
    'English words with and' => ['two thousand and fifty', 2050.0],
    'hundreds shorthand' => ['fifteen hundred', 1500.0],
    'a thousand' => ['a thousand cedis', 1000.0],
    'Twi month count' => ['asram asia', 6.0],
    'Twi twelve' => ['abosome dummienu', 12.0],
    'no number' => ['me nnim', null],
]);

test('reads Ewe month counts', function () {
    expect(app(NumberParser::class)->parse('ɣleti ade', language('ewe')))->toBe(6.0);
});
