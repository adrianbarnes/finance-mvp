<?php

namespace App\Console\Commands;

use App\Models\VoicePrompt;
use App\Services\Audio\VoicePromptBundle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('voice-prompts:export')]
#[Description('Write every voice prompt (wording and audio) to the committed bundle that VoicePromptSeeder loads')]
class ExportVoicePrompts extends Command
{
    public function handle(VoicePromptBundle $bundle): int
    {
        $result = $bundle->export(VoicePrompt::query()->get());

        $this->info("Exported {$result['prompts']} prompt(s), {$result['with_audio']} with audio, to {$bundle->path()}.");
        $this->line('Commit that folder so other installs get the same prompts.');

        return self::SUCCESS;
    }
}
