<?php

namespace App\Console\Commands;

use App\Enums\Language;
use App\Models\VoicePrompt;
use App\Services\Abena\AbenaTextToSpeech;
use App\Services\Audio\AudioTranscodingFailed;
use App\Services\Audio\VoicePromptAudioStore;
use App\Services\Speech\TextToSpeechFailed;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

#[Signature('voice-prompts:synthesize
    {--language=twi : Language code of the prompts to voice}
    {--voice= : Abena voice ID (defaults to the configured voice for the language)}
    {--key=* : Only voice these prompt keys}
    {--force : Replace audio that already exists}')]
#[Description('Generate audio for voice prompts with Abena AI text-to-speech')]
class SynthesizeVoicePrompts extends Command
{
    public function handle(AbenaTextToSpeech $textToSpeech, VoicePromptAudioStore $audioStore): int
    {
        $language = Language::tryFrom((string) $this->option('language'));

        if ($language === null) {
            $this->error('Unknown language. Use one of: '.implode(', ', array_column(Language::cases(), 'value')));

            return self::FAILURE;
        }

        $voice = $this->option('voice') ?: config("services.abena.voices.{$language->value}");

        if (blank($voice)) {
            $this->error("No Abena voice is configured for {$language->label()}.");

            return self::FAILURE;
        }

        $prompts = VoicePrompt::query()
            ->where('language', $language)
            ->when(! $this->option('force'), fn ($query) => $query->whereNull('audio_path'))
            ->when($this->option('key') !== [], fn ($query) => $query->whereIn('key', $this->option('key')))
            ->orderBy('key')
            ->get();

        if ($prompts->isEmpty()) {
            $this->info('Every prompt already has audio. Use --force to regenerate.');

            return self::SUCCESS;
        }

        $this->info("Voicing {$prompts->count()} {$language->label()} prompt(s) with Abena AI ({$voice}).");
        $generated = 0;

        foreach ($prompts as $index => $prompt) {
            if ($index > 0) {
                Sleep::for((float) config('services.abena.pause_seconds'))->seconds();
            }

            try {
                $audioStore->store($prompt, $textToSpeech->synthesize($prompt->text, $voice));
                $generated++;
                $this->line("  <info>✓</info> {$prompt->key}");
            } catch (TextToSpeechFailed $exception) {
                $this->line("  <error>✗</error> {$prompt->key}: {$exception->getMessage()}");

                if ($exception->isAllowanceExhausted()) {
                    $this->warn(blank(config('services.abena.key'))
                        ? 'The Abena AI free allowance is used up. Add ABENA_API_KEY to .env, then run the command again to voice the rest.'
                        : 'The Abena AI credits for this API key are used up. Add credits on the Abena dashboard, then run the command again to voice the rest.');

                    break;
                }
            } catch (AudioTranscodingFailed $exception) {
                $this->line("  <error>✗</error> {$prompt->key}: {$exception->getMessage()}");
            }
        }

        $this->info("Generated audio for {$generated} of {$prompts->count()} prompt(s).");

        return $generated === $prompts->count() ? self::SUCCESS : self::FAILURE;
    }
}
