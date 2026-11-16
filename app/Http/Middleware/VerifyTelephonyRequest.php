<?php

namespace App\Http\Middleware;

use App\Services\Telephony\TelephonyProviders;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts a telephony webhook only when its provider confirms it is
 * genuine (for Twilio, the X-Twilio-Signature).
 */
class VerifyTelephonyRequest
{
    public function __construct(private TelephonyProviders $providers) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $provider = $this->providers->get((string) $request->route('provider'));

        abort_unless($provider->isAuthentic($request), 403, 'This request could not be verified as coming from the phone provider.');

        return $next($request);
    }
}
