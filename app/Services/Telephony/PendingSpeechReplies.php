<?php

namespace App\Services\Telephony;

use App\Models\CallSession;
use App\Services\Conversation\AssistantReply;
use App\Services\Conversation\CallInput;
use App\Services\Conversation\ConversationEngine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Recognising a caller's speech (Khaya) can take longer than a phone
 * provider waits for a webhook (15 seconds on Twilio). The answer webhook
 * hands the audio over and keeps the caller on hold; the reply is worked
 * out after the response is sent, and fetched with the ticket once ready.
 */
class PendingSpeechReplies
{
    /**
     * How long a finished reply waits to be fetched.
     */
    private const int KeepSeconds = 300;

    /**
     * Seconds the deferred work may run: Khaya's timeout plus the audio
     * clean-up, with room to spare.
     */
    private const int WorkSeconds = 90;

    public function __construct(private ConversationEngine $engine) {}

    /**
     * Start working out the reply to the caller's speech, after the
     * response has been sent.
     *
     * @return string The ticket to fetch the reply with
     */
    public function start(CallSession $call, string $audio, string $mimeType): string
    {
        $ticket = (string) Str::ulid();

        defer(function () use ($call, $audio, $mimeType, $ticket): void {
            set_time_limit(self::WorkSeconds);

            try {
                $reply = $this->engine->handleSpeech($call->refresh(), $audio, $mimeType);
            } catch (Throwable $exception) {
                report($exception);
                $reply = $this->engine->handleInput($call->refresh(), CallInput::noInput());
            }

            // Plain data: the cache does not unserialize objects (config/cache.php).
            Cache::put($this->key($call, $ticket), get_object_vars($reply), self::KeepSeconds);
        });

        return $ticket;
    }

    /**
     * The reply once it is ready; it can be fetched once.
     */
    public function pull(CallSession $call, string $ticket): ?AssistantReply
    {
        $reply = Cache::pull($this->key($call, $ticket));

        return is_array($reply) ? new AssistantReply(...$reply) : null;
    }

    private function key(CallSession $call, string $ticket): string
    {
        return "telephony:speech-reply:{$call->uuid}:{$ticket}";
    }
}
