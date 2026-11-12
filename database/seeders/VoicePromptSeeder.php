<?php

namespace Database\Seeders;

use App\Services\Audio\VoicePromptBundle;
use Illuminate\Database\Seeder;

/**
 * Loads every voice prompt (wording and audio) from the committed bundle in
 * database/seeders/data/voice-prompts. Twi and Ewe wording are drafts for
 * native speakers to review; after editing or regenerating prompts in the
 * app, run `php artisan voice-prompts:export` and commit the bundle.
 */
class VoicePromptSeeder extends Seeder
{
    public function run(VoicePromptBundle $bundle): void
    {
        $result = $bundle->import();

        $this->command?->info("Voice prompts: {$result['created']} created, audio attached to {$result['audio_attached']}.");
    }
}
