<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\ScheduledMessage;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduledMessageService
{
    public const MAX_BODY_LENGTH = 4000;

    public const MAX_DAYS_AHEAD = 30;

    public function schedule(User $sender, Conversation $conversation, string $clientMessageId, string $body, Carbon $sendAt): ScheduledMessage
    {
        if ($conversation->e2ee_enabled) {
            throw ValidationException::withMessages([
                'body' => __('Scheduled messages are not available in end-to-end encrypted conversations.'),
            ]);
        }

        $existing = ScheduledMessage::query()
            ->where('sender_id', $sender->id)
            ->where('client_message_id', $clientMessageId)
            ->first();

        if ($existing !== null) {
            if ($existing->conversation_id !== $conversation->id) {
                throw ValidationException::withMessages([
                    'client_message_id' => __('This client message identifier has already been used.'),
                ]);
            }

            return $existing;
        }

        if (Message::query()
            ->where('sender_id', $sender->id)
            ->where('client_message_id', $clientMessageId)
            ->exists()) {
            throw ValidationException::withMessages([
                'client_message_id' => __('This client message identifier has already been used.'),
            ]);
        }

        try {
            return ScheduledMessage::query()->create([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'client_message_id' => $clientMessageId,
                'body' => trim($body),
                'send_at' => $sendAt->utc(),
                'status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = ScheduledMessage::query()
                ->where('sender_id', $sender->id)
                ->where('client_message_id', $clientMessageId)
                ->first();

            if ($existing !== null && $existing->conversation_id === $conversation->id) {
                return $existing;
            }

            throw $exception;
        }
    }

    public function cancel(User $sender, ScheduledMessage $scheduled): bool
    {
        return DB::transaction(function () use ($sender, $scheduled): bool {
            $locked = ScheduledMessage::query()
                ->whereKey($scheduled->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->sender_id !== $sender->id || $locked->status !== 'pending') {
                return false;
            }

            $locked->forceFill(['status' => 'cancelled'])->save();

            return true;
        });
    }

    /** Deliver due rows in small locked batches; retries are safe via the original client id. */
    public function dispatchDue(MessageService $messages, int $limit = 100): int
    {
        $processedCount = 0;

        for ($index = 0; $index < $limit; $index++) {
            $processed = DB::transaction(function () use ($messages): bool {
                $scheduled = ScheduledMessage::query()
                    ->where('status', 'pending')
                    ->where('send_at', '<=', now())
                    ->orderBy('send_at')
                    ->lockForUpdate()
                    ->first();

                if ($scheduled === null) {
                    return false;
                }

                $conversation = Conversation::query()->find($scheduled->conversation_id);
                $member = $conversation?->members()
                    ->where('users.id', $scheduled->sender_id)
                    ->wherePivotNull('left_at')
                    ->exists();
                $sender = User::query()->find($scheduled->sender_id);

                if (! $conversation || ! $member || ! $sender || ! $sender->isActive()) {
                    $scheduled->forceFill([
                        'status' => 'failed',
                        'last_error' => 'The sender or conversation is no longer available.',
                    ])->save();

                    return true;
                }

                $scheduled->forceFill(['status' => 'dispatching'])->save();

                try {
                    [$message] = $messages->send($sender, $conversation, [
                        'client_message_id' => $scheduled->client_message_id,
                        'body' => $scheduled->body,
                        'attachment_ids' => [],
                        'type' => 'text',
                    ]);
                } catch (ValidationException) {
                    $scheduled->forceFill([
                        'status' => 'failed',
                        'last_error' => 'The message no longer meets current sending rules.',
                    ])->save();

                    return true;
                }

                $scheduled->forceFill([
                    'status' => 'sent',
                    'message_id' => $message->id,
                    'sent_at' => now(),
                    'last_error' => null,
                ])->save();

                return true;
            });

            if (! $processed) {
                break;
            }

            $processedCount++;
        }

        return $processedCount;
    }
}
