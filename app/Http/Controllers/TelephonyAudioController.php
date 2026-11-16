<?php

namespace App\Http\Controllers;

use App\Services\Flows\ClipRecordingStore;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves prompt audio to phone providers with an audio Content-Type (the
 * web server labels .wav files application/octet-stream, which Twilio
 * refuses to play). Files are named by their content and never change,
 * so providers may cache them for good.
 */
class TelephonyAudioController extends Controller
{
    /**
     * Folders on the public disk that hold prompt audio.
     */
    public const array Folders = [ClipRecordingStore::Folder, 'speech-cache'];

    private const array ContentTypes = ['wav' => 'audio/wav', 'mp3' => 'audio/mpeg'];

    public function __invoke(string $path): StreamedResponse
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $folder = explode('/', $path, 2)[0];

        abort_unless(
            isset(self::ContentTypes[$extension])
                && in_array($folder, self::Folders, true)
                && ! str_contains($path, '..')
                && Storage::disk('public')->exists($path),
            404,
        );

        return Storage::disk('public')->response($path, headers: [
            'Content-Type' => self::ContentTypes[$extension],
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
