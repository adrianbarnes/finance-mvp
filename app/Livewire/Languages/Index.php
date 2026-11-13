<?php

namespace App\Livewire\Languages;

use App\Enums\SpeechRecognition;
use App\Models\Language;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Manage the languages the voice assistant offers: add, edit, switch on
 * or off, reorder, choose the primary language, delete and restore.
 */
#[Title('Languages')]
class Index extends Component
{
    public ?int $editingLanguageId = null;

    public string $name = '';

    public string $code = '';

    public string $speechRecognition = 'khaya';

    public string $khayaCode = '';

    public string $selectionWords = '';

    public string $yesWords = '';

    public string $noWords = '';

    public string $numberWords = '';

    /**
     * @return Collection<int, Language>
     */
    #[Computed]
    public function languages(): Collection
    {
        return Language::query()->ordered()->get();
    }

    /**
     * @return Collection<int, Language>
     */
    #[Computed]
    public function deletedLanguages(): Collection
    {
        return Language::onlyTrashed()->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('language-form')->show();
    }

    public function edit(int $languageId): void
    {
        $language = Language::query()->findOrFail($languageId);

        $this->resetForm();
        $this->editingLanguageId = $language->id;
        $this->name = $language->name;
        $this->code = $language->code;
        $this->speechRecognition = $language->speech_recognition->value;
        $this->khayaCode = (string) $language->khaya_code;
        $this->selectionWords = implode(', ', $language->selection_words);
        $this->yesWords = implode(', ', $language->yes_words);
        $this->noWords = implode(', ', $language->no_words);
        $this->numberWords = collect($language->number_words)
            ->map(fn (int $number, string $word): string => "{$word} = {$number}")
            ->implode("\n");

        Flux::modal('language-form')->show();
    }

    public function save(): void
    {
        $this->code = Str::lower(trim($this->code));
        $this->khayaCode = Str::lower(trim($this->khayaCode));

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:50'],
            'code' => $this->editingLanguageId === null
                ? ['required', 'regex:/^[a-z]{2,8}$/', Rule::unique('languages', 'code')]
                : [],
            'speechRecognition' => ['required', Rule::enum(SpeechRecognition::class)],
            'khayaCode' => ['nullable', 'required_if:speechRecognition,khaya', 'regex:/^[a-z]{2,8}$/'],
            'selectionWords' => ['nullable', 'string', 'max:1000'],
            'yesWords' => ['nullable', 'string', 'max:1000'],
            'noWords' => ['nullable', 'string', 'max:1000'],
            'numberWords' => ['nullable', 'string', 'max:5000'],
        ], [
            'code.unique' => Language::onlyTrashed()->where('code', $this->code)->exists()
                ? __('A deleted language uses this code. Restore it under Deleted languages instead.')
                : __('Another language already uses this code.'),
            'code.regex' => __('Use the 2–8 letter ISO code, e.g. twi.'),
            'khayaCode.required_if' => __('Enter the language code Khaya expects, e.g. twi.'),
        ]);

        $attributes = [
            'name' => $validated['name'],
            'speech_recognition' => $validated['speechRecognition'],
            'khaya_code' => $validated['speechRecognition'] === SpeechRecognition::Khaya->value ? $validated['khayaCode'] : null,
            'selection_words' => $this->parseWords($this->selectionWords),
            'yes_words' => $this->parseWords($this->yesWords),
            'no_words' => $this->parseWords($this->noWords),
            'number_words' => $this->parseNumberWords($this->numberWords),
        ];

        if ($this->editingLanguageId === null) {
            $language = Language::query()->create([
                ...$attributes,
                'code' => $validated['code'],
                'position' => (int) Language::withTrashed()->max('position') + 1,
                'enabled' => false,
            ]);

            Flux::toast(variant: 'success', text: __(':language added. Switch it on when its audio is ready.', ['language' => $language->name]));
        } else {
            Language::query()->findOrFail($this->editingLanguageId)->update($attributes);
            Flux::toast(variant: 'success', text: __(':language saved.', ['language' => $validated['name']]));
        }

        $this->resetForm();
        unset($this->languages);
        Flux::modal('language-form')->close();
    }

    public function toggleEnabled(int $languageId): void
    {
        $language = Language::query()->findOrFail($languageId);

        if ($language->is_primary && $language->enabled) {
            Flux::toast(variant: 'danger', text: __('The primary language cannot be switched off. Make another language primary first.'));

            return;
        }

        $language->update(['enabled' => ! $language->enabled]);
        unset($this->languages);

        Flux::toast(text: $language->enabled
            ? __(':language is switched on and offered to callers.', ['language' => $language->name])
            : __(':language is switched off. Callers who saved it will be asked for a language again.', ['language' => $language->name]));
    }

    public function makePrimary(int $languageId): void
    {
        $language = Language::query()->findOrFail($languageId);
        $language->makePrimary();
        unset($this->languages);

        Flux::toast(variant: 'success', text: __(':language is now the primary language.', ['language' => $language->name]));
    }

    /**
     * Drag-and-drop handler: move a language to a zero-based position.
     */
    public function sort(int $languageId, int $position): void
    {
        $ordered = Language::query()->ordered()->get();
        $moved = $ordered->firstWhere('id', $languageId);
        abort_if($moved === null, 404);

        $reordered = $ordered->reject(fn (Language $language): bool => $language->is($moved))->values();
        $reordered->splice(max(0, min($position, $reordered->count())), 0, [$moved]);

        DB::transaction(function () use ($reordered): void {
            foreach ($reordered->values() as $index => $language) {
                $language->update(['position' => $index + 1]);
            }
        });

        unset($this->languages);
    }

    public function delete(int $languageId): void
    {
        $language = Language::query()->findOrFail($languageId);

        if ($language->is_primary) {
            Flux::toast(variant: 'danger', text: __('The primary language cannot be deleted. Make another language primary first.'));

            return;
        }

        $language->delete();
        unset($this->languages, $this->deletedLanguages);

        Flux::toast(text: __(':language deleted. Its words and audio are kept, so you can restore it.', ['language' => $language->name]));
    }

    public function restore(int $languageId): void
    {
        $language = Language::onlyTrashed()->findOrFail($languageId);
        $language->restore();
        unset($this->languages, $this->deletedLanguages);

        Flux::toast(variant: 'success', text: __(':language restored.', ['language' => $language->name]));
    }

    public function render(): View
    {
        return view('livewire.languages.index', [
            'recognitionOptions' => SpeechRecognition::cases(),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset('editingLanguageId', 'name', 'code', 'speechRecognition', 'khayaCode', 'selectionWords', 'yesWords', 'noWords', 'numberWords');
        $this->resetValidation();
    }

    /**
     * Words separated by commas or new lines, without blanks or repeats.
     *
     * @return list<string>
     */
    private function parseWords(string $words): array
    {
        $cleaned = array_map(fn (string $word): string => Str::lower(trim($word)), preg_split('/[,\n]+/', $words) ?: []);

        return array_values(array_unique(array_filter($cleaned, fn (string $word): bool => $word !== '')));
    }

    /**
     * Lines of "word = number", e.g. "baako = 1".
     *
     * @return array<string, int>
     */
    private function parseNumberWords(string $lines): array
    {
        $numberWords = [];

        foreach (preg_split('/\n+/', trim($lines)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^\s*(.+?)\s*=\s*(\d+)\s*$/u', $line, $matches) !== 1) {
                throw ValidationException::withMessages([
                    'numberWords' => __('Write one number word per line as "word = number", e.g. "baako = 1". Check: :line', ['line' => trim($line)]),
                ]);
            }

            $numberWords[Str::lower($matches[1])] = (int) $matches[2];
        }

        return $numberWords;
    }
}
