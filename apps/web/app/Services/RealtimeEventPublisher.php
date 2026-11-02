<?php

namespace App\Services;

use App\Http\Resources\ContactRequestResource;
use App\Http\Resources\MessageResource;
use App\Models\ContactRequest;
use App\Models\Conversation;
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
 *
 * Conversation aggregates fan out to conversation subscribers; user aggregates
 * fan out to every socket the target user has open, which is how contact
 * requests and blocks reach a device that is not subscribed to anything.
 */
class RealtimeEventPublisher
{
    public const VERSION = 1;

    public const AGGREGATE_CONVERSATION = 'conversation';

    public const AGGREGATE_USER = 'user';

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

    /**
     * Persist one user-scoped event, delivered to every device of that user.
     *
     * @param  array<string, mixed>  $data
     */
    public function publishUserEvent(string $eventType, string $userId, array $data): void
    {
        OutboxEvent::query()->create([
            'aggregate_type' => self::AGGREGATE_USER,
            'aggregate_id' => $userId,
            'event_type' => $eventType,
            'payload' => [
                'version' => self::VERSION,
                'event_id' => (string) Str::uuid(),
                'type' => $eventType,
                'occurred_at' => Carbon::now()->toIso8601ZuluString(),
                'data' => $data,
            ],
        ]);
    }

    /**
     * Notify the receiver that a contact request is waiting for an answer.
     */
    public function contactRequestCreated(ContactRequest $request, string $recipientId): void
    {
        $this->publishUserEvent(
            'contact.request.created',
            $recipientId,
            $this->contactRequestPayload($request),
        );
    }

    /**
     * Tell both sides of a request that it moved to its final state.
     *
     * @param  array<int, string>  $recipientIds
     */
    public function contactRequestUpdated(ContactRequest $request, array $recipientIds): void
    {
        $payload = $this->contactRequestPayload($request);

        foreach (array_unique($recipientIds) as $recipientId) {
            $this->publishUserEvent('contact.request.updated', $recipientId, $payload);
        }
    }

    /**
     * Address book entries changed for both users of a removed pair.
     */
    public function contactUpdated(string $userId, string $otherUserId): void
    {
        $this->publishUserEvent('contact.updated', $userId, [
            'user_id' => $userId,
            'contact_id' => $otherUserId,
        ]);

        $this->publishUserEvent('contact.updated', $otherUserId, [
            'user_id' => $otherUserId,
            'contact_id' => $userId,
        ]);
    }

    public function blockUpdated(string $userId, string $blockedUserId, bool $blocked): void
    {
        $this->publishUserEvent('block.updated', $userId, [
            'user_id' => $userId,
            'blocked_user_id' => $blockedUserId,
            'blocked' => $blocked,
        ]);
    }

    /**
     * Conversation metadata or the member list changed.
     *
     * The payload is deliberately not a full conversation representation: read
     * cursors and unread counts are per viewer and cannot be shared by every
     * subscriber, so clients refetch the conversation over HTTP.
     */
    public function conversationUpdated(Conversation $conversation, string $reason): void
    {
        $this->publishConversationEvent(
            'conversation.updated',
            (string) $conversation->id,
            null,
            [
                'conversation_id' => (string) $conversation->id,
                'reason' => $reason,
            ],
        );
    }

    /**
     * Tell specific users that a conversation they may not be subscribed to yet
     * changed, so their client refetches the conversation list.
     *
     * @param  array<int, string>  $userIds
     */
    public function conversationChangedForUsers(Conversation $conversation, array $userIds, string $reason): void
    {
        foreach (array_unique($userIds) as $userId) {
            $this->publishUserEvent('conversation.updated', $userId, [
                'conversation_id' => (string) $conversation->id,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * A user lost access to a conversation (removed, left or deleted group).
     *
     * This one is user-scoped because the conversation aggregate no longer
     * reaches them once their subscription is gone.
     */
    public function conversationRemoved(Conversation $conversation, string $userId): void
    {
        $this->publishUserEvent('conversation.removed', $userId, [
            'conversation_id' => (string) $conversation->id,
        ]);
    }

    public function messagePinned(Message $message, string $pinnedByUserId): void
    {
        $this->publishConversationEvent(
            'message.pinned',
            (string) $message->conversation_id,
            (int) $message->seq,
            [
                'conversation_id' => (string) $message->conversation_id,
                'message_id' => (string) $message->id,
                'pinned_by' => $pinnedByUserId,
            ],
        );
    }

    public function messageUnpinned(Message $message): void
    {
        $this->publishConversationEvent(
            'message.unpinned',
            (string) $message->conversation_id,
            (int) $message->seq,
            [
                'conversation_id' => (string) $message->conversation_id,
                'message_id' => (string) $message->id,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function contactRequestPayload(ContactRequest $request): array
    {
        $request->loadMissing(['sender', 'receiver']);

        return (new ContactRequestResource($request))->resolve(self::request());
    }

    private function publishMessageEvent(string $eventType, Message $message): void
    {
        $message->loadMissing(['sender', 'replyTo.sender', 'attachments']);

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
