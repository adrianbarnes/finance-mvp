<?php

namespace App\Livewire\VoicePrompts;

use App\Enums\Language;
use App\Models\VoicePrompt;
use App\Services\Abena\AbenaTextToSpeech;
use App\Services\Audio\AudioTranscodingFailed;
use App\Services\Audio\VoicePromptAudioStore;
use App\Services\Speech\TextToSpeechFailed;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Test panel: edit the wording of each prompt and upload its recording.
 * Audio can be uploaded or generated with Abena AI text-to-speech; either
 * way it is converted with ffmpeg to MP3 (browser) and 8 kHz WAV (IVR).
 */
#[Title('Voice prompts')]
class Index extends Component
{
    use WithFileUploads;

    public string $language = 'twi';

    public ?int $editingPromptId = null;

    public string $text = '';

    /**
     * @var TemporaryUploadedFile|null
     */
    public $recording = null;

    /**
     * @return Collection<int, VoicePrompt>
     */
    #[Computed]
    public function prompts(): Collection
    {
        $groupOrder = array_flip(array_keys(config('conversation.prompt_groups')));

        return VoicePrompt::query()->where('language', $this->language)->orderBy('key')->get()
            ->sortBy(fn (VoicePrompt $prompt): int => $groupOrder[$prompt->group()] ?? PHP_INT_MAX)
            ->values();
    }

    public function edit(int $promptId): void
    {
        $prompt = VoicePrompt::query()->findOrFail($promptId);

        $this->editingPromptId = $prompt->id;
        $this->text = $prompt->text;
        $this->recording = null;
        $this->resetValidation();

        Flux::modal('edit-prompt')->show();
    }

    public function save(VoicePromptAudioStore $audioStore): void
    {
        $this->validate([
            'text' => ['required', 'string', 'max:2000'],
            'recording' => ['nullable', 'file', 'max:10240', 'mimetypes:audio/mpeg,audio/wav,audio/x-wav,audio/wave,audio/ogg,audio/webm,video/webm,audio/mp4,audio/x-m4a,audio/aac,audio/flac,audio/x-flac'],
        ]);

        $prompt = VoicePrompt::query()->findOrFail($this->editingPromptId);
        $prompt->update(['text' => $this->text]);

        if ($this->recording instanceof UploadedFile) {
            try {
                $audioStore->store($prompt, (string) $this->recording->get());
            } catch (AudioTranscodingFailed) {
                $this->addError('recording', __('The recording could not be converted. Try a WAV or MP3 file.'));

                return;
            }
        }

        $this->reset('editingPromptId', 'text', 'recording');
        unset($this->prompts);
        Flux::modal('edit-prompt')->close();
        Flux::toast(variant: 'success', text: __('Prompt saved.'));
    }

    /**
     * Voice the prompt text with Abena AI text-to-speech.
     */
    public function generate(int $promptId, AbenaTextToSpeech $textToSpeech, VoicePromptAudioStore $audioStore): void
    {
        $prompt = VoicePrompt::query()->findOrFail($promptId);
        $voice = config("services.abena.voices.{$prompt->language->value}");

        if (blank($voice)) {
            Flux::toast(variant: 'danger', text: __('No Abena voice is configured for :language.', ['language' => $prompt->language->label()]));

            return;
        }

        try {
            $audioStore->store($prompt, $textToSpeech->synthesize($prompt->text, $voice));
        } catch (TextToSpeechFailed|AudioTranscodingFailed $exception) {
            Flux::toast(variant: 'danger', text: __('Could not generate audio: :error', ['error' => $exception->getMessage()]));

            return;
        }

        unset($this->prompts);
        Flux::toast(variant: 'success', text: __('Audio generated for :key.', ['key' => $prompt->key]));
    }

    public function removeRecording(int $promptId, VoicePromptAudioStore $audioStore): void
    {
        $prompt = VoicePrompt::query()->findOrFail($promptId);

        $audioStore->delete($prompt);
        $prompt->update(['audio_path' => null, 'telephony_audio_path' => null]);

        unset($this->prompts);
        Flux::toast(text: __('Recording removed.'));
    }

    public function render(): View
    {
        return view('livewire.voice-prompts.index', [
            'languages' => Language::enabled(),
        ]);
    }
}
