<?php

namespace App\Http\Controllers;

use App\Enums\CallChannel;
use App\Models\CallSession;
use App\Services\Conversation\CallInput;
use App\Services\Conversation\ConversationEngine;
use App\Services\Telephony\PendingSpeechReplies;
use App\Services\Telephony\TelephonyProvider;
use App\Services\Telephony\TelephonyProviders;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phone provider webhooks: a call is answered, the caller answers, the
 * caller says nothing, or the call ends. The provider translates its own
 * requests and responses; the IVR flow runs in the same engine as the
 * browser phone.
 */
class TelephonyController extends Controller
{
    /**
     * Hold-on rounds (about a second each) before giving up on a reply.
     */
    private const int MaxHoldAttempts = 60;

    public function __construct(
        private ConversationEngine $engine,
        private TelephonyProviders $providers,
        private PendingSpeechReplies $pendingReplies,
    ) {}

    /**
     * A call was answered: someone rang the provider's number, or picked up
     * a call placed from the call console. Runs the published flow, or the
     * draft with ?draft=1.
     */
    public function incoming(Request $request, string $provider): Response
    {
        $telephony = $this->providers->get($provider);
        $incoming = $telephony->incomingCall($request);

        [$call, $reply] = $this->engine->startCall(
            $incoming->customerNumber,
            CallChannel::Ivr,
            $incoming->direction,
            useDraft: $request->boolean('draft'),
            telephonyProvider: $telephony->name(),
            externalCallId: $incoming->callId,
        );

        return $telephony->reply($call, $reply);
    }

    /**
     * The caller pressed keys, spoke, or hung up.
     */
    public function answer(Request $request, string $provider, CallSession $call): Response
    {
        $telephony = $this->providers->get($provider);
        $this->ensureSameCall($request, $telephony, $call);

        if ($call->hasEnded()) {
            return $telephony->hangUp();
        }

        $response = $telephony->callerResponse($request, $call);

        if ($response instanceof Response) {
            return $response;
        }

        if ($response->hungUp) {
            $this->engine->hangUp($call);

            return $telephony->hangUp();
        }

        if ($response->audio !== null) {
            $ticket = $this->pendingReplies->start($call, $response->audio, $response->audioMimeType);

            return $telephony->holdOn($call, $ticket, 1);
        }

        return $telephony->reply($call, $this->engine->handleInput($call, $response->input ?? CallInput::noInput()));
    }

    /**
     * The caller is on hold while their speech is recognised: play the
     * reply once it is ready, or hold on a little longer.
     */
    public function result(Request $request, string $provider, CallSession $call): Response
    {
        $telephony = $this->providers->get($provider);
        $this->ensureSameCall($request, $telephony, $call);

        $ticket = (string) $request->query('ticket');
        $attempt = (int) $request->query('attempt', '1');
        $reply = $ticket === '' ? null : $this->pendingReplies->pull($call, $ticket);

        if ($reply !== null) {
            return $telephony->reply($call->refresh(), $reply);
        }

        if ($call->refresh()->hasEnded() || $attempt >= self::MaxHoldAttempts) {
            $this->engine->hangUp($call);

            return $telephony->hangUp();
        }

        return $telephony->holdOn($call, $ticket, $attempt + 1);
    }

    /**
     * The caller said nothing before the provider stopped listening.
     */
    public function noInput(Request $request, string $provider, CallSession $call): Response
    {
        $telephony = $this->providers->get($provider);
        $this->ensureSameCall($request, $telephony, $call);

        if ($call->hasEnded()) {
            return $telephony->hangUp();
        }

        return $telephony->reply($call, $this->engine->handleInput($call, CallInput::noInput()));
    }

    /**
     * Call status changed: close the call once it is over.
     */
    public function status(Request $request, string $provider): Response
    {
        $telephony = $this->providers->get($provider);
        $callId = $telephony->callIdFrom($request);

        if ($callId !== null && $telephony->callHasEnded($request)) {
            $call = CallSession::query()
                ->where('telephony_provider', $telephony->name())
                ->where('external_call_id', $callId)
                ->first();

            if ($call !== null) {
                $this->engine->hangUp($call);
            }
        }

        return response()->noContent();
    }

    /**
     * Webhooks for a call must come from the provider call that started it.
     */
    private function ensureSameCall(Request $request, TelephonyProvider $telephony, CallSession $call): void
    {
        abort_unless(
            $call->channel === CallChannel::Ivr
                && $call->telephony_provider === $telephony->name()
                && filled($call->external_call_id)
                && $call->external_call_id === $telephony->callIdFrom($request),
            404,
        );
    }
}
