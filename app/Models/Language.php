<?php

namespace App\Models;

use App\Enums\SpeechRecognition;
use Carbon\CarbonImmutable;
use Database\Factories\LanguageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A language the voice assistant can speak and understand. The order of
 * the enabled languages is the order they are offered on the language
 * choice, and a language's place in it is the key that picks it.
 *
 * @property int $id
 * @property string $code ISO 639-3, e.g. "twi"
 * @property string $name
 * @property SpeechRecognition $speech_recognition
 * @property string|null $khaya_code
 * @property list<string> $selection_words Words that pick this language on the language choice
 * @property list<string> $yes_words
 * @property list<string> $no_words
 * @property array<string, int> $number_words
 * @property int $position
 * @property bool $enabled
 * @property bool $is_primary
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable(['code', 'name', 'speech_recognition', 'khaya_code', 'selection_words', 'yes_words', 'no_words', 'number_words', 'position', 'enabled'])]
class Language extends Model
{
    /** @use HasFactory<LanguageFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'speech_recognition' => SpeechRecognition::class,
            'selection_words' => 'array',
            'yes_words' => 'array',
            'no_words' => 'array',
            'number_words' => 'array',
            'position' => 'integer',
            'enabled' => 'boolean',
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    protected function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }

    /**
     * Enabled languages in the order they are offered to callers.
     *
     * @return Collection<int, self>
     */
    public static function enabledInOrder(): Collection
    {
        return self::query()->where('enabled', true)->ordered()->get();
    }

    /**
     * The main IVR language: used until the caller chooses one, and the
     * first fallback when a step has no audio in the caller's language.
     */
    public static function primary(): self
    {
        return self::query()->where('is_primary', true)->firstOrFail();
    }

    public static function findByCode(string $code): ?self
    {
        return self::query()->where('code', $code)->first();
    }

    /**
     * Whether the browser phone recognises this language's speech itself,
     * instead of sending it to the server.
     */
    public function isRecognizedByClient(): bool
    {
        return $this->speech_recognition === SpeechRecognition::Browser;
    }

    /**
     * Whether a caller can answer by speaking in this language at all.
     */
    public function acceptsSpeech(): bool
    {
        return $this->speech_recognition !== SpeechRecognition::Keypad;
    }

    /**
     * Key that picks this language on the language choice: its place among
     * the enabled languages, starting at 1. Null when it is not offered.
     */
    public function keypadNumber(): ?string
    {
        $index = self::enabledInOrder()->search(fn (self $language): bool => $language->is($this));

        return $index === false ? null : (string) ($index + 1);
    }

    /**
     * Make this the primary language, enabling it if needed. Exactly one
     * language is primary at a time.
     */
    public function makePrimary(): void
    {
        DB::transaction(function (): void {
            self::query()->whereKeyNot($this->getKey())->where('is_primary', true)->update(['is_primary' => false]);

            $this->forceFill(['is_primary' => true, 'enabled' => true])->save();
        });
    }
}
