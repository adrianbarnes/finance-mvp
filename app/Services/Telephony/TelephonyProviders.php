<?php

namespace App\Services\Telephony;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The providers configured in config/telephony.php, by name.
 */
class TelephonyProviders
{
    public function __construct(private Container $container) {}

    /**
     * @throws InvalidArgumentException When no provider has that name
     */
    public function get(string $name): TelephonyProvider
    {
        $config = config("telephony.providers.{$name}");

        if (! is_array($config) || ! is_string($config['driver'] ?? null)) {
            throw new InvalidArgumentException("Telephony provider [{$name}] is not configured.");
        }

        $provider = $this->container->make($config['driver'], ['name' => $name, 'config' => $config]);

        if (! $provider instanceof TelephonyProvider) {
            throw new InvalidArgumentException("Telephony provider [{$name}] must implement ".TelephonyProvider::class.'.');
        }

        return $provider;
    }

    /**
     * The provider the call console rings phones through.
     */
    public function default(): TelephonyProvider
    {
        return $this->get((string) config('telephony.default'));
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map('strval', array_keys((array) config('telephony.providers')));
    }
}
