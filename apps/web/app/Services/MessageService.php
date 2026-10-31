<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageEdit;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MessageService
{
    public const DEFAULT_LIMIT = 30;

    public const MAX_LIMIT = 100;

    public const MAX_BODY_LENGTH = 4000;

    public function __construct(private readonly RealtimeEventPublisher $events) {}

    /**
     * Fetch one page of messages, newest first internally but returned in ascending order.
     *
     * @return array{0: Collection<int, Message>, 1: bool, 2: int|null}
     */
    public function history(Conversation $conversation, ?int $beforeSeq = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $query = $conversation->messages()
            ->withTrashed()
            ->with(['sender', 'replyTo.sender'])
            ->orderByDesc('seq');

        if ($beforeSeq !== null) {
            $query->where('seq', '<', $beforeSeq);
        }

        $messages = $query->limit($limit + 1)->get();

        $hasMore = $messages->count() > $limit;

        $messages = $messages
            ->take($limit)
            ->reverse()
            ->values();

        return [$messages, $hasMore, $messages->first()?->seq];
    }

    /**
     * Persist a message transactionally and allocate its conversation sequence.
     *
     * @param  array{client_message_id: string, body: string, type?: string, reply_to_message_id?: string|null}  $data
     * @return array{0: Message, 1: bool} The message and whether it was newly created.
     */
    public function send(User $sender, Conversation $conversation, array $data): array
    {
        $body = trim($data['body']);

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => __('A message body is required.'),
            ]);
        }

        $clientMessageId = $data['client_message_id'];
        $replyToMessageId = $data['reply_to_message_id'] ?? null;
        $type = $data['type'] ?? 'text';

        $existing = $this->findByIdempotencyKey($sender, $clientMessageId);

        if ($existing !== null) {
            return [$this->assertSameConversation($existing, $conversation), false];
        }

        try {
            return DB::transaction(function () use ($sender, $conversation, $clientMessageId, $body, $replyToMessageId, $type): array {
                $lockedConversation = Conversation::query()
                    ->lockForUpdate()
                    ->findOrFail($conversation->id);

                $existing = $this->findByIdempotencyKey($sender, $clientMessageId);

                if ($existing !== null) {
                    return [$this->assertSameConversation($existing, $lockedConversation), false];
                }

                if ($replyToMessageId !== null) {
                    $this->assertReplyBelongsToConversation($lockedConversation, $replyToMessageId);
                }

                $seq = (int) $lockedConversation->next_message_seq + 1;

                $message = Message::query()->create([
                    'conversation_id' => $lockedConversation->id,
                    'seq' => $seq,
                    'sender_id' => $sender->id,
                    'client_message_id' => $clientMessageId,
                    'type' => $type,
                    'body' => $body,
                    'reply_to_message_id' => $replyToMessageId,
                ]);

                $lockedConversation->forceFill([
                    'next_message_seq' => $seq,
                    'last_message_id' => $message->id,
                ])->save();

                DB::table('conversation_members')
                    ->where('conversation_id', $lockedConversation->id)
                    ->where('user_id', $sender->id)
                    ->where('last_read_seq', '<', $seq)
                    ->update([
                        'last_read_seq' => $seq,
                        'updated_at' => Carbon::now(),
                    ]);

                $message = $message->load(['sender', 'replyTo.sender']);

                // Same transaction as the insert: the event exists if and only
                // if the message was committed.
                $this->events->messageCreated($message);

                return [$message, true];
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findByIdempotencyKey($sender, $clientMessageId);

            if ($existing !== null) {
                return [$this->assertSameConversation($existing, $conversation), false];
            }

            throw $exception;
        }
    }

    public function edit(User $editor, Message $message, string $body): Message
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => __('A message body is required.'),
            ]);
        }

        if ($message->deleted_at !== null) {
            throw ValidationException::withMessages([
                'body' => __('Deleted messages cannot be edited.'),
            ]);
        }

        if ($message->body === $body) {
            return $message->load(['sender', 'replyTo.sender']);
        }

        DB::transaction(function () use ($editor, $message, $body): void {
            $previousBody = $message->body;

            $message->forceFill([
                'body' => $body,
                'edited_at' => Carbon::now(),
            ])->save();

            MessageEdit::query()->create([
                'message_id' => $message->id,
                'editor_id' => $editor->id,
                'previous_body' => $previousBody,
                'current_body' => $body,
            ]);

            $this->events->messageUpdated($message->load(['sender', 'replyTo.sender']));
        });

        return $message->load(['sender', 'replyTo.sender']);
    }

    public function delete(Message $message): Message
    {
        DB::transaction(function () use ($message): void {
            $conversationId = $message->conversation_id;
            $wasLastMessage = $message->conversation->last_message_id === $message->id;

            $message->forceFill([
                'body' => null,
                'deleted_at' => Carbon::now(),
            ])->save();

            if ($wasLastMessage) {
                $previous = Message::query()
                    ->where('conversation_id', $conversationId)
                    ->whereKeyNot($message->id)
                    ->whereNull('deleted_at')
                    ->orderByDesc('seq')
                    ->first();

                DB::table('conversations')
                    ->where('id', $conversationId)
                    ->update(['last_message_id' => $previous?->id]);
            }

            // The tombstone (null body, deleted_at) is what subscribers render,
            // so the realtime frame must carry the deleted state.
            $this->events->messageDeleted($message->load(['sender', 'replyTo.sender']));
        });

        return $message->fresh(['sender', 'replyTo.sender']);
    }

    private function findByIdempotencyKey(User $sender, string $clientMessageId): ?Message
    {
        return Message::query()
            ->withTrashed()
            ->where('sender_id', $sender->id)
            ->where('client_message_id', $clientMessageId)
            ->first();
    }

    private function assertSameConversation(Message $message, Conversation $conversation): Message
    {
        if ($message->conversation_id !== $conversation->id) {
            throw ValidationException::withMessages([
                'client_message_id' => __('This client message identifier has already been used.'),
            ]);
        }

        return $message->load(['sender', 'replyTo.sender']);
    }

    private function assertReplyBelongsToConversation(Conversation $conversation, string $replyToMessageId): void
    {
        $exists = Message::query()
            ->whereKey($replyToMessageId)
            ->where('conversation_id', $conversation->id)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'reply_to_message_id' => __('The message being replied to is not part of this conversation.'),
            ]);
        }
    }
}
