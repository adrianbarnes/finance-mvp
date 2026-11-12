<?php

namespace App\Http\Controllers;

use App\Enums\CallChannel;
use App\Http\Requests\StartCallRequest;
use App\Http\Requests\StoreCallInputRequest;
use App\Http\Requests\StoreCallUtteranceRequest;
use App\Models\CallSession;
use App\Models\CallTurn;
use App\Services\Conversation\AssistantReply;
use App\Services\Conversation\CallInput;
use App\Services\Conversation\ConversationEngine;
use App\Services\Khaya\KhayaQuotaGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * JSON endpoints used by the WebRTC test console to place a call, send
 * caller speech or keypad input, and receive the assistant's reply.
 */
class CallController extends Controller
{
    public function __construct(
        private ConversationEngine $engine,
        private KhayaQuotaGuard $quotaGuard,
    ) {}

    public function store(StartCallRequest $request): JsonResponse
    {
        [$call, $reply] = $this->engine->startCall($request->validated('caller_number'), CallChannel::WebRtc);

        return response()->json([
            'call' => [
                'uuid' => $call->uuid,
                'customer' => $call->customer?->full_name,
            ],
            ...$this->payload($reply, $call),
        ], 201);
    }

    public function utterance(StoreCallUtteranceRequest $request, CallSession $call): JsonResponse
    {
        $this->ensureCallIsActive($call);

        $audio = $request->file('audio');
        $contents = $audio->get();

        abort_if($contents === false, 422, 'The audio upload could not be read.');

        return response()->json($this->payload(
            $this->engine->handleSpeech($call, $contents, (string) $audio->getMimeType()),
            $call,
        ));
    }

    public function input(StoreCallInputRequest $request, CallSession $call): JsonResponse
    {
        $this->ensureCallIsActive($call);

        $input = match (true) {
            $request->filled('digits') => CallInput::keypad($request->validated('digits')),
            $request->filled('text') => CallInput::text($request->validated('text')),
            $request->filled('transcript') => CallInput::speech($request->validated('transcript')),
            default => CallInput::noInput(),
        };

        return response()->json($this->payload($this->engine->handleInput($call, $input), $call));
    }

    public function destroy(CallSession $call): Response
    {
        $this->engine->hangUp($call);

        return response()->noContent();
    }

    public function turnAudio(CallTurn $turn): StreamedResponse
    {
        abort_if($turn->audio_path === null || ! Storage::disk('local')->exists($turn->audio_path), 404);

        return Storage::disk('local')->response($turn->audio_path);
    }

    private function ensureCallIsActive(CallSession $call): void
    {
        abort_if($call->hasEnded(), 409, 'This call has ended.');
    }

    /**
     * @return array{reply: array<string, mixed>, language: array{code: string, recognized_by_client: bool}|null, quota: array<string, int|string>}
     */
    private function payload(AssistantReply $reply, CallSession $call): array
    {
        return [
            'reply' => $reply->toArray(),
            'language' => $call->language === null ? null : [
                'code' => $call->language->value,
                'recognized_by_client' => $call->language->isRecognizedByClient(),
            ],
            'quota' => $this->quotaGuard->summary(),
        ];
    }
}
