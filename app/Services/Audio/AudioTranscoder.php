<?php

namespace App\Services\Audio;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Converts audio with ffmpeg so every channel (browser now, IVR later)
 * hands the same format to Khaya, and prompts suit each playback channel.
 */
class AudioTranscoder
{
    private const int RecognitionSampleRate = 16_000;

    private const int WavHeaderBytes = 44;

    /**
     * Normalise caller audio for speech recognition and trim the silence.
     *
     * @throws AudioTranscodingFailed
     */
    public function prepareForRecognition(string $audio): PreparedAudio
    {
        return $this->withTemporaryFiles($audio, 'wav', function (string $input, string $output): PreparedAudio {
            $this->run([
                '-i', $input,
                '-af', 'highpass=f=80,silenceremove=start_periods=1:start_threshold=-45dB:start_silence=0.15'
                    .':stop_periods=-1:stop_threshold=-45dB:stop_duration=0.6:stop_silence=0.3',
                '-ac', '1',
                '-ar', (string) self::RecognitionSampleRate,
                '-c:a', 'pcm_s16le',
                '-map_metadata', '-1',
                '-fflags', '+bitexact',
                $output,
            ]);

            $contents = (string) file_get_contents($output);
            $bytesPerSecond = self::RecognitionSampleRate * 2;

            return new PreparedAudio($contents, max(0, strlen($contents) - self::WavHeaderBytes) / $bytesPerSecond);
        });
    }

    /**
     * Mono MP3 for playback in the browser phone.
     *
     * @throws AudioTranscodingFailed
     */
    public function forBrowserPlayback(string $audio): string
    {
        return $this->convert($audio, 'mp3', ['-ac', '1', '-c:a', 'libmp3lame', '-q:a', '4']);
    }

    /**
     * 8 kHz mono 16-bit WAV, the narrowband format IVR platforms play.
     *
     * @throws AudioTranscodingFailed
     */
    public function forTelephony(string $audio): string
    {
        return $this->convert($audio, 'wav', ['-ac', '1', '-ar', '8000', '-c:a', 'pcm_s16le']);
    }

    /**
     * @param  list<string>  $outputOptions
     */
    private function convert(string $audio, string $extension, array $outputOptions): string
    {
        return $this->withTemporaryFiles($audio, $extension, function (string $input, string $output) use ($outputOptions): string {
            $this->run(['-i', $input, ...$outputOptions, '-map_metadata', '-1', $output]);

            return (string) file_get_contents($output);
        });
    }

    /**
     * @template TResult
     *
     * @param  callable(string, string): TResult  $callback
     * @return TResult
     */
    private function withTemporaryFiles(string $audio, string $outputExtension, callable $callback): mixed
    {
        $basePath = sys_get_temp_dir().'/audio-'.Str::ulid();
        $input = $basePath.'-in';
        $output = $basePath.'-out.'.$outputExtension;

        file_put_contents($input, $audio);

        try {
            return $callback($input, $output);
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments): void
    {
        $result = Process::timeout(60)->run([
            (string) config('services.ffmpeg.binary'),
            '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
            ...$arguments,
        ]);

        if ($result->failed()) {
            throw new AudioTranscodingFailed('ffmpeg failed: '.trim($result->errorOutput()));
        }
    }
}
