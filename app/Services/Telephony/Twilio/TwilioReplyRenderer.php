<?php

namespace App\Services\Telephony\Twilio;

use App\Enums\SpeechRecognition;
use App\Enums\StepType;
use App\Models\CallSession;
use App\Models\Language;
use App\Services\Conversation\AssistantReply;
use App\Services\Telephony\PublicUrls;
use DOMElement;
use Illuminate\Http\Response;

/**
 * Turns the assistant's reply into TwiML. Recordings play with <Play> and
 * wording without a recording is read with <Say>. How the caller answers
 * depends on the call language:
 *
 * - Khaya languages (Twi, Ewe): <Record>, sent to Khaya afterwards. Any key
 *   ends the recording and counts as keypad input.
 * - Browser-recognised languages (English, for testing): <Gather> with
 *   Twilio's own speech recognition, or keys.
 * - Keypad-only languages: <Gather> for keys.
 */
class TwilioReplyRenderer
{
    /**
     * Keys that end a recording; the key pressed is the caller's answer.
     */
    private const string RecordingKeys = '0123456789*#';

    /**
     * @param  array<string, mixed>  $config  The provider's entry in config/telephony.php
     */
    public function __construct(
        private string $provider,
        private array $config,
        private PublicUrls $urls,
    ) {}

    public function render(CallSession $call, AssistantReply $reply): Response
    {
        $twiml = new Twiml;

        if ($reply->hasEnded()) {
            $this->speak($twiml, $reply);
            $twiml->add('Hangup');

            return $this->response($twiml);
        }

        $call->unsetRelation('language');
        $language = $call->language ?? Language::primary();
        $answerUrl = $this->callUrl('telephony.calls.answer', $call);

        if ($language->speech_recognition === SpeechRecognition::Khaya && $reply->stepType !== null) {
            $this->speak($twiml, $reply);
            $twiml->add('Record', [
                'action' => $answerUrl,
                'method' => 'POST',
                'timeout' => (int) ($this->config['record_silence_seconds'] ?? 4),
                'maxLength' => (int) ($this->config['record_max_seconds'] ?? 20),
                'finishOnKey' => self::RecordingKeys,
                'playBeep' => false,
                'trim' => 'trim-silence',
            ]);

            return $this->response($twiml);
        }

        $acceptsSpeech = $language->speech_recognition === SpeechRecognition::Browser;
        $gather = $twiml->add('Gather', [
            'action' => $answerUrl,
            'method' => 'POST',
            'input' => $acceptsSpeech ? 'dtmf speech' : 'dtmf',
            'language' => $acceptsSpeech ? (string) ($this->config['speech_language'] ?? 'en-GB') : null,
            'speechTimeout' => $acceptsSpeech ? 'auto' : null,
            'timeout' => (int) config('conversation.no_input_timeout_seconds'),
            ...$this->keysFor($reply),
        ]);
        $this->speak($twiml, $reply, $gather);
        $twiml->add('Redirect', ['method' => 'POST'], $this->callUrl('telephony.calls.no-input', $call));

        return $this->response($twiml);
    }

    /**
     * After a caller starts typing a number during a recording, collect the
     * rest of it. The keys already pressed travel in the link.
     */
    public function continueNumber(CallSession $call, string $typed): Response
    {
        $twiml = new Twiml;
        $twiml->add('Gather', [
            'action' => $this->callUrl('telephony.calls.answer', $call, ['typed' => $typed]),
            'method' => 'POST',
            'input' => 'dtmf',
            'finishOnKey' => '#',
            'timeout' => (int) config('conversation.no_input_timeout_seconds'),
            'actionOnEmptyResult' => true,
        ]);

        return $this->response($twiml);
    }

    /**
     * A second of silence, then ask whether the reply is ready.
     */
    public function holdOn(CallSession $call, string $ticket, int $attempt): Response
    {
        $twiml = new Twiml;
        $twiml->add('Pause', ['length' => 1]);
        $twiml->add('Redirect', ['method' => 'POST'], $this->callUrl('telephony.calls.result', $call, ['ticket' => $ticket, 'attempt' => (string) $attempt]));

        return $this->response($twiml);
    }

    public function hangUp(): Response
    {
        $twiml = new Twiml;
        $twiml->add('Hangup');

        return $this->response($twiml);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function callUrl(string $route, CallSession $call, array $query = []): string
    {
        return $this->urls->route($route, ['provider' => $this->provider, 'call' => $call->uuid, ...$query]);
    }

    /**
     * A number is typed in full and ended with #; anything else is one key.
     *
     * @return array{numDigits?: int, finishOnKey: string}
     */
    private function keysFor(AssistantReply $reply): array
    {
        return $reply->stepType === StepType::AskNumber->value
            ? ['finishOnKey' => '#']
            : ['numDigits' => 1, 'finishOnKey' => ''];
    }

    private function speak(Twiml $twiml, AssistantReply $reply, ?DOMElement $parent = null): void
    {
        foreach ($reply->segments as $segment) {
            $audioUrl = $segment['telephony_audio_url'] ?? $segment['audio_url'] ?? null;

            if (filled($audioUrl)) {
                $twiml->add('Play', [], $this->urls->audio((string) $audioUrl), $parent);
            } elseif (filled($segment['text'])) {
                $twiml->add('Say', ['voice' => filled($this->config['say_voice'] ?? null) ? (string) $this->config['say_voice'] : null], $segment['text'], $parent);
            }
        }
    }

    private function response(Twiml $twiml): Response
    {
        return response($twiml->toXml(), 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }
}
