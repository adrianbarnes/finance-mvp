<?php

namespace App\Services\Telephony;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Links that a phone provider follows (audio and webhooks) must use the
 * public address it reaches the app on, such as a tunnel, not the local one.
 */
class PublicUrls
{
    public function __construct(private Request $request) {}

    /**
     * The configured public address, or the host the provider called.
     */
    public function base(): string
    {
        $configured = rtrim((string) config('telephony.public_url'), '/');

        return $configured !== '' ? $configured : $this->request->getSchemeAndHttpHost();
    }

    public function isConfigured(): bool
    {
        return filled(config('telephony.public_url'));
    }

    /**
     * The same path and query on the public address.
     */
    public function public(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return $this->base().'/'.ltrim($path, '/').(is_string($query) && $query !== '' ? '?'.$query : '');
    }

    /**
     * A prompt recording on the public disk (e.g. ".../storage/flow-audio/x.wav"),
     * served with an audio Content-Type for providers to play.
     */
    public function audio(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $storagePrefix = rtrim((string) parse_url(Storage::disk('public')->url(''), PHP_URL_PATH), '/').'/';

        if (! str_starts_with($path, $storagePrefix)) {
            return $this->public($url);
        }

        return $this->route('telephony.audio', ['path' => substr($path, strlen($storagePrefix))]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function route(string $name, array $parameters = []): string
    {
        return $this->public(route($name, $parameters, absolute: false));
    }

    /**
     * The URL the provider requested, as it would have signed it.
     */
    public function current(): string
    {
        return $this->base().$this->request->getRequestUri();
    }
}
