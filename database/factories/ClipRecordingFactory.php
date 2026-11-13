<?php

namespace Database\Factories;

use App\Models\AudioClip;
use App\Models\ClipRecording;
use App\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClipRecording>
 */
class ClipRecordingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'audio_clip_id' => AudioClip::factory(),
            'language_id' => fn (): ?int => Language::findByCode('twi')?->id,
            'text' => fake()->sentence(),
            'audio_path' => 'flow-audio/'.fake()->sha1().'.mp3',
            'telephony_audio_path' => null,
            'original_filename' => 'recording.wav',
            'duration_ms' => 1500,
        ];
    }
}
