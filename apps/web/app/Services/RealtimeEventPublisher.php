<?php

namespace App\Services;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\OutboxEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Writes realtime events to the transactional outbox.
 *
 * Nothing here talks to the broker directly: the event row is committed with
 * the domain change, and the Go worker publishes it to NATS. That keeps a
 * message from being delivered when it was never committed, and from being
 * lost when the broker is briefly unavailable.
 */
class RealtimeEventPublisher
{
    public const VERSION = 1;

    public const AGGREGATE_CONVERSATION = 'conversation';

    public function messageCreated(Message $message): void
    {
        $this->publishMessageEvent('message.created', $message);
    }

    public function messageUpdated(Message $message): void
    {
        $this->publishMessageEvent('message.updated', $message);
    }

    public function messageDeleted(Message $message): void
    {
        $this->publishMessageEvent('message.deleted', $message);
    }

    /**
     * Persist one conversation-scoped event for the gateway to fan out.
     *
     * @param  array<string, mixed>  $data
     */
    public function publishConversationEvent(string $eventType, string $conversationId, ?int $seq, array $data): void
    {
        OutboxEvent::query()->create([
            'aggregate_type' => self::AGGREGATE_CONVERSATION,
            'aggregate_id' => $conversationId,
            'event_type' => $eventType,
            'payload' => [
                'version' => self::VERSION,
                'event_id' => (string) Str::uuid(),
                'type' => $eventType,
                'conversation_id' => $conversationId,
                'seq' => $seq,
                'occurred_at' => Carbon::now()->toIso8601ZuluString(),
                'data' => $data,
            ],
        ]);
    }

    private function publishMessageEvent(string $eventType, Message $message): void
    {
        $message->loadMissing(['sender', 'replyTo.sender']);

        $this->publishConversationEvent(
            $eventType,
            (string) $message->conversation_id,
            (int) $message->seq,
            (new MessageResource($message))->resolve(self::request()),
        );
    }

    /**
     * The event payload mirrors the HTTP resource exactly, so clients can treat
     * a realtime message frame like any other message representation.
     */
    private static function request(): Request
    {
        $request = app()->bound('request') ? app('request') : null;

        return $request instanceof Request ? $request : Request::create('/');
    }
}
