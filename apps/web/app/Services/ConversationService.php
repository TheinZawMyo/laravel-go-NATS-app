<?php

namespace App\Services;

use App\Enums\ConversationRole;
use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConversationService
{
    public function __construct(
        private readonly RealtimeEventPublisher $events,
        private readonly PresenceService $presence,
        private readonly ContactService $contacts,
    ) {}

    /**
     * Open the direct conversation between two users, creating it once.
     */
    public function openDirect(User $actor, User $other): Conversation
    {
        if ($actor->is($other)) {
            throw ValidationException::withMessages([
                'username' => __('You cannot start a conversation with yourself.'),
            ]);
        }

        if (! $other->isActive()) {
            throw ValidationException::withMessages([
                'username' => __('This account is not available.'),
            ]);
        }

        if ($this->contacts->hasBlockBetween($actor, $other)) {
            throw ValidationException::withMessages([
                'username' => __('You cannot start a conversation with this user.'),
            ]);
        }

        $pairKey = Conversation::directPairKey($actor->id, $other->id);

        try {
            return DB::transaction(function () use ($actor, $other, $pairKey): Conversation {
                $conversation = Conversation::query()
                    ->where('direct_pair_key', $pairKey)
                    ->lockForUpdate()
                    ->first();

                if ($conversation !== null) {
                    return $conversation;
                }

                $conversation = Conversation::query()->create([
                    'type' => ConversationType::Direct,
                    'created_by' => $actor->id,
                    'direct_pair_key' => $pairKey,
                    'history_visible_to_new_members' => true,
                ]);

                $joinedAt = Carbon::now();

                $conversation->members()->attach($actor->id, [
                    'role' => ConversationRole::Member->value,
                    'joined_at' => $joinedAt,
                ]);

                $conversation->members()->attach($other->id, [
                    'role' => ConversationRole::Member->value,
                    'joined_at' => $joinedAt,
                ]);

                return $conversation;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = Conversation::query()
                ->where('direct_pair_key', $pairKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            throw ValidationException::withMessages([
                'username' => __('The conversation could not be created. Please try again.'),
            ]);
        }
    }

    /**
     * Load the data every conversation representation needs for one viewer.
     *
     * @return Collection<int, Conversation>
     */
    public function summariesFor(User $user): Collection
    {
        $conversations = Conversation::query()
            ->whereHas('members', function (Builder $query) use ($user): void {
                $query->whereKey($user->id)
                    ->whereNull('conversation_members.left_at');
            })
            ->with(['lastMessage.sender', 'lastMessage.attachments', 'members'])
            ->orderByDesc('updated_at')
            ->get();

        $unreadCounts = $this->unreadCountsFor($user, $conversations->pluck('id'));

        $this->presence->annotate($conversations->flatMap(
            fn (Conversation $conversation): Collection => $conversation->members,
        ));

        return $conversations->each(
            fn (Conversation $conversation): Conversation => $this->applyViewerState(
                $conversation,
                $user,
                $unreadCounts[$conversation->id] ?? 0,
            ),
        );
    }

    public function prepareForViewer(User $user, Conversation $conversation): Conversation
    {
        $conversation->loadMissing(['lastMessage.sender', 'lastMessage.attachments', 'members']);

        $unreadCounts = $this->unreadCountsFor($user, collect([$conversation->id]));

        $this->presence->annotate($conversation->members);

        return $this->applyViewerState(
            $conversation,
            $user,
            $unreadCounts[$conversation->id] ?? 0,
        );
    }

    /**
     * Mute or unmute notifications for one membership.
     *
     * `muted_until` is the only place mute is stored; "forever" is represented
     * by a far future timestamp so the column stays a single source of truth.
     */
    public function setMuted(User $user, Conversation $conversation, ?Carbon $until): Conversation
    {
        DB::table('conversation_members')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->update([
                'muted_until' => $until,
                'updated_at' => Carbon::now(),
            ]);

        return $this->prepareForViewer($user, $conversation->refresh());
    }

    public function setArchived(User $user, Conversation $conversation, bool $archived): Conversation
    {
        DB::table('conversation_members')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->update([
                'archived_at' => $archived ? Carbon::now() : null,
                'updated_at' => Carbon::now(),
            ]);

        return $this->prepareForViewer($user, $conversation->refresh());
    }

    /**
     * Advance a membership's read cursor without allowing it to move backwards.
     *
     * The cursor is the authoritative read state; the realtime event is only
     * published when the cursor actually moved, so replays and stale clients
     * cannot fan out duplicate receipts.
     */
    public function markRead(User $user, Conversation $conversation, int $seq): int
    {
        $seq = max(0, min($seq, (int) $conversation->next_message_seq));

        return DB::transaction(function () use ($user, $conversation, $seq): int {
            $advanced = DB::table('conversation_members')
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $user->id)
                ->whereNull('left_at')
                ->where('last_read_seq', '<', $seq)
                ->update([
                    'last_read_seq' => $seq,
                    'updated_at' => Carbon::now(),
                ]);

            $lastReadSeq = (int) DB::table('conversation_members')
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $user->id)
                ->value('last_read_seq');

            if ($advanced > 0 && $lastReadSeq > 0) {
                $this->publishReadReceipt($user, $conversation, $lastReadSeq);
            }

            return $lastReadSeq;
        });
    }

    /**
     * Queue the receipt other members render as "read up to seq".
     *
     * The message id is best effort: a cursor can advance past a message that
     * was deleted, and the sequence remains the authoritative marker.
     */
    private function publishReadReceipt(User $user, Conversation $conversation, int $seq): void
    {
        $messageId = Message::query()
            ->withTrashed()
            ->where('conversation_id', $conversation->id)
            ->where('seq', $seq)
            ->value('id');

        $this->events->publishConversationEvent(
            'receipt.updated',
            (string) $conversation->id,
            $seq,
            [
                'conversation_id' => (string) $conversation->id,
                'user_id' => (string) $user->id,
                'message_id' => is_string($messageId) ? $messageId : '',
                'seq' => $seq,
                'read' => true,
            ],
        );
    }

    /**
     * @param  Collection<int, string>  $conversationIds
     * @return array<string, int>
     */
    private function unreadCountsFor(User $user, Collection $conversationIds): array
    {
        if ($conversationIds->isEmpty()) {
            return [];
        }

        return DB::table('messages')
            ->join('conversation_members', function ($join) use ($user): void {
                $join->on('conversation_members.conversation_id', '=', 'messages.conversation_id')
                    ->where('conversation_members.user_id', '=', $user->id)
                    ->whereNull('conversation_members.left_at');
            })
            ->whereIn('messages.conversation_id', $conversationIds)
            ->whereColumn('messages.seq', '>', 'conversation_members.last_read_seq')
            // Messages hidden by the history setting never count as unread.
            ->whereColumn('messages.seq', '>=', 'conversation_members.history_from_seq')
            ->where('messages.sender_id', '!=', $user->id)
            ->whereNull('messages.deleted_at')
            ->groupBy('messages.conversation_id')
            ->selectRaw('messages.conversation_id as conversation_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'conversation_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    private function applyViewerState(Conversation $conversation, User $user, int $unreadCount): Conversation
    {
        $membership = $conversation->members->firstWhere('id', $user->id);

        $conversation->setAttribute('viewer_last_read_seq', (int) ($membership?->pivot->last_read_seq ?? 0));
        $conversation->setAttribute('viewer_history_from_seq', (int) ($membership?->pivot->history_from_seq ?? 0));
        $conversation->setAttribute('viewer_role', $membership?->pivot?->role?->value);
        $conversation->setAttribute('muted_until', $membership?->pivot?->muted_until);
        $conversation->setAttribute('viewer_archived', $membership?->pivot?->archived_at !== null);
        $conversation->setAttribute('unread_count', $unreadCount);

        return $conversation;
    }
}
