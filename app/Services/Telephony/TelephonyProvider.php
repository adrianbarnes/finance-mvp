<?php

namespace App\Services\Telephony;

use App\Models\CallSession;
use App\Services\Conversation\AssistantReply;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A phone provider that carries IVR calls (Twilio today; another provider
 * or our own FreeSWITCH engine later). The provider translates between its
 * webhooks and the conversation engine: the IVR flow, the call records and
 * speech recognition stay the same whichever provider carries the call.
 *
 * Webhooks reach /telephony/{name}/voice, /status, and
 * /telephony/{name}/calls/{call}/answer, /no-input and /result.
 */
interface TelephonyProvider
{
    /**
     * The key in config/telephony.php, used in webhook links.
     */
    public function name(): string;

    /**
     * Settings still to fill in before the provider can be used.
     *
     * @return list<string> e.g. ["TWILIO_AUTH_TOKEN"]
     */
    public function missingSettings(): array;

    /**
     * Ring a phone. When it is answered, the provider requests the voice
     * webhook and the IVR flow runs, the draft when $useDraft is set.
     *
     * @param  string  $phoneNumber  In E.164, e.g. "+233244123456"
     * @return string The provider's id for the call
     *
     * @throws CallNotPlaced
     */
    public function placeCall(string $phoneNumber, bool $useDraft = false): string;

    /**
     * Whether a webhook really comes from the provider.
     */
    public function isAuthentic(Request $request): bool;

    /**
     * The provider's id for the call a webhook is about.
     */
    public function callIdFrom(Request $request): ?string;

    /**
     * A call was answered: one coming in, or one placed with placeCall().
     */
    public function incomingCall(Request $request): IncomingCall;

    /**
     * What the caller said or pressed. Returns the provider's own response
     * instead when it must collect more before there is an answer.
     */
    public function callerResponse(Request $request, CallSession $call): CallerResponse|Response;

    /**
     * Whether a call-status webhook says the call is over.
     */
    public function callHasEnded(Request $request): bool;

    /**
     * Play the assistant's reply, then wait for the caller's answer (or
     * hang up when the call has ended).
     */
    public function reply(CallSession $call, AssistantReply $reply): Response;

    /**
     * Keep the caller on the line while their speech is recognised, then
     * request /telephony/{name}/calls/{call}/result with the ticket and the
     * attempt number, about a second later.
     */
    public function holdOn(CallSession $call, string $ticket, int $attempt): Response;

    /**
     * End the call.
     */
    public function hangUp(): Response;
}
