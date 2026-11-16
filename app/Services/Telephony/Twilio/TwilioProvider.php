<?php

namespace App\Services\Telephony\Twilio;

use App\Enums\CallDirection;
use App\Enums\StepType;
use App\Models\CallSession;
use App\Services\Conversation\AssistantReply;
use App\Services\Conversation\CallInput;
use App\Services\Telephony\CallerResponse;
use App\Services\Telephony\CallNotPlaced;
use App\Services\Telephony\IncomingCall;
use App\Services\Telephony\PublicUrls;
use App\Services\Telephony\TelephonyProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carries IVR calls over Twilio Programmable Voice: TwiML replies, webhook
 * signatures (X-Twilio-Signature), recordings for Khaya languages, and the
 * REST API to ring phones.
 */
class TwilioProvider implements TelephonyProvider
{
    private const string ApiUrl = 'https://api.twilio.com/2010-04-01';

    /**
     * Twilio call statuses that mean the call is over.
     */
    private const array FinishedStatuses = ['completed', 'busy', 'failed', 'no-answer', 'canceled'];

    private TwilioReplyRenderer $renderer;

    /**
     * @param  array<string, mixed>  $config  This provider's entry in config/telephony.php
     */
    public function __construct(
        private string $name,
        private array $config,
        private PublicUrls $urls,
        private TwilioRecordings $recordings,
    ) {
        $this->renderer = new TwilioReplyRenderer($name, $config, $urls);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function missingSettings(): array
    {
        $settings = [
            'TWILIO_ACCOUNT_SID' => $this->config['account_sid'] ?? null,
            'TWILIO_AUTH_TOKEN' => $this->config['auth_token'] ?? null,
            'TWILIO_PHONE_NUMBER' => $this->config['phone_number'] ?? null,
            'TELEPHONY_PUBLIC_URL' => $this->urls->isConfigured() ? 'set' : null,
        ];

        return array_keys(array_filter($settings, fn (mixed $value): bool => blank($value)));
    }

    public function placeCall(string $phoneNumber, bool $useDraft = false): string
    {
        $missing = $this->missingSettings();

        if ($missing !== []) {
            throw new CallNotPlaced(__('Set :settings in .env to call phones.', ['settings' => implode(', ', $missing)]));
        }

        $accountSid = (string) $this->config['account_sid'];

        try {
            $response = Http::asForm()
                ->withBasicAuth($accountSid, (string) $this->config['auth_token'])
                ->timeout(15)
                ->post(self::ApiUrl."/Accounts/{$accountSid}/Calls.json", [
                    'To' => $phoneNumber,
                    'From' => (string) $this->config['phone_number'],
                    'Url' => $this->urls->route('telephony.voice', ['provider' => $this->name, ...($useDraft ? ['draft' => 1] : [])]),
                    'Method' => 'POST',
                    'StatusCallback' => $this->urls->route('telephony.status', ['provider' => $this->name]),
                    'StatusCallbackMethod' => 'POST',
                ]);
        } catch (ConnectionException $exception) {
            throw new CallNotPlaced(__('Twilio could not be reached: :error', ['error' => $exception->getMessage()]), previous: $exception);
        }

        $callId = $response->json('sid');

        if ($response->failed() || ! is_string($callId)) {
            throw new CallNotPlaced((string) ($response->json('message') ?? __('Twilio refused the call (HTTP :status).', ['status' => $response->status()])));
        }

        return $callId;
    }

    /**
     * The X-Twilio-Signature must match an HMAC-SHA1, keyed with the auth
     * token, of the URL Twilio called followed by each POST parameter's
     * name and value in name order.
     */
    public function isAuthentic(Request $request): bool
    {
        if (! ($this->config['validate_signature'] ?? true)) {
            return true;
        }

        $authToken = (string) ($this->config['auth_token'] ?? '');
        $signature = (string) $request->header('X-Twilio-Signature');

        if ($authToken === '' || $signature === '') {
            return false;
        }

        // The raw body, so input trimming cannot change what was signed.
        $body = $request->getContent();
        $parameters = $request->request->all();

        if ($body !== '') {
            parse_str($body, $parameters);
        }

        return hash_equals(self::signature($this->urls->current(), $parameters, $authToken), $signature);
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     */
    public static function signature(string $url, array $parameters, string $authToken): string
    {
        ksort($parameters, SORT_STRING);

        $data = $url;

        foreach ($parameters as $name => $value) {
            $data .= $name.(is_scalar($value) ? (string) $value : '');
        }

        return base64_encode(hash_hmac('sha1', $data, $authToken, true));
    }

    public function callIdFrom(Request $request): ?string
    {
        $callId = $request->input('CallSid');

        return is_string($callId) && $callId !== '' ? $callId : null;
    }

    public function incomingCall(Request $request): IncomingCall
    {
        $isOutbound = str_starts_with((string) $request->input('Direction'), 'outbound');

        return new IncomingCall(
            callId: (string) $this->callIdFrom($request),
            customerNumber: (string) $request->input($isOutbound ? 'To' : 'From', ''),
            direction: $isOutbound ? CallDirection::Outbound : CallDirection::Inbound,
        );
    }

    /**
     * Keys (also a key that ended a recording), Twilio-recognised speech,
     * or a recording. A first key on a number question during a recording
     * starts keypad entry for the rest of the number.
     */
    public function callerResponse(Request $request, CallSession $call): CallerResponse|Response
    {
        $digits = (string) $request->input('Digits', '');

        if ($digits === 'hangup') {
            return CallerResponse::hungUp();
        }

        $typed = (string) $request->query('typed', '');

        if ($typed !== '') {
            return CallerResponse::input(CallInput::keypad($typed.$digits));
        }

        if ($digits !== '' && $request->filled('RecordingUrl') && $call->state === StepType::AskNumber->value && ctype_digit($digits)) {
            return $this->renderer->continueNumber($call, $digits);
        }

        if ($digits !== '') {
            return CallerResponse::input(CallInput::keypad($digits));
        }

        if ($request->filled('SpeechResult')) {
            return CallerResponse::input(CallInput::speech((string) $request->input('SpeechResult')));
        }

        $audio = $request->filled('RecordingUrl')
            ? $this->recordings->download((string) $request->input('RecordingUrl'), $this->config)
            : null;

        return $audio === null || $audio === ''
            ? CallerResponse::input(CallInput::noInput())
            : CallerResponse::audio($audio);
    }

    public function callHasEnded(Request $request): bool
    {
        return in_array($request->input('CallStatus'), self::FinishedStatuses, true);
    }

    public function reply(CallSession $call, AssistantReply $reply): Response
    {
        return $this->renderer->render($call, $reply);
    }

    public function holdOn(CallSession $call, string $ticket, int $attempt): Response
    {
        return $this->renderer->holdOn($call, $ticket, $attempt);
    }

    public function hangUp(): Response
    {
        return $this->renderer->hangUp();
    }
}
