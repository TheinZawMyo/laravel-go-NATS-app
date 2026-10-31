<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ConversationRole;
use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\ConversationInvite;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Group conversations: creation, membership, roles and invite links.
 *
 * The role hierarchy is Owner > Admin > Moderator > Member. Owners and admins
 * run the group, moderators may moderate content (pin), and only the owner can
 * hand out or take away roles. Every guard is enforced here as well as in the
 * policy so no caller can reach the model layer with an invalid transition.
 */
class GroupService
{
    public const MAX_MEMBERS = 200;

    public const MAX_TITLE_LENGTH = 120;

    public const MAX_DESCRIPTION_LENGTH = 500;

    public function __construct(
        private readonly RealtimeEventPublisher $events,
        private readonly ContactService $contacts,
    ) {}

    /**
     * @param  array{title: string, description?: string|null, history_visible_to_new_members?: bool, member_ids?: array<int, string>}  $data
     */
    public function create(User $owner, array $data): Conversation
    {
        $memberIds = collect($data['member_ids'] ?? [])
            ->reject(fn (string $id): bool => $id === $owner->id)
            ->unique()
            ->values()
            ->all();

        if (count($memberIds) + 1 > self::MAX_MEMBERS) {
            throw ValidationException::withMessages([
                'member_ids' => __('A group can have at most :count members.', ['count' => self::MAX_MEMBERS]),
            ]);
        }

        $members = $this->activeUsers($memberIds);

        foreach ($members as $member) {
            if ($this->contacts->hasBlockBetween($owner, $member)) {
                throw ValidationException::withMessages([
                    'member_ids' => __('You cannot add a user you have blocked or who blocked you.'),
                ]);
            }
        }

        $conversation = DB::transaction(function () use ($owner, $data, $members): Conversation {
            $conversation = Conversation::query()->create([
                'type' => ConversationType::Group,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'created_by' => $owner->id,
                'history_visible_to_new_members' => $data['history_visible_to_new_members'] ?? true,
            ]);

            $joinedAt = Carbon::now();

            $conversation->members()->attach($owner->id, [
                'role' => ConversationRole::Owner->value,
                'joined_at' => $joinedAt,
                'history_from_seq' => 0,
            ]);

            foreach ($members as $member) {
                $conversation->members()->attach($member->id, [
                    'role' => ConversationRole::Member->value,
                    'joined_at' => $joinedAt,
                    'history_from_seq' => 0,
                ]);
            }

            return $conversation;
        });

        // The creator learns about the group from the HTTP response; everyone
        // else is not subscribed yet and needs the user-scoped nudge.
        $this->events->conversationChangedForUsers(
            $conversation,
            $members->map(fn (User $member): string => (string) $member->id)->all(),
            'member_added',
        );

        return $conversation;
    }

    /**
     * @param  array{title?: string, description?: string|null, avatar_key?: string|null, history_visible_to_new_members?: bool}  $data
     */
    public function updateProfile(User $actor, Conversation $conversation, array $data): Conversation
    {
        $this->assertGroup($conversation);
        $this->assertCanManage($actor, $conversation);

        $attributes = array_filter([
            'title' => $data['title'] ?? null,
            'description' => array_key_exists('description', $data) ? $data['description'] : null,
            'avatar_key' => $data['avatar_key'] ?? null,
        ], fn ($value): bool => $value !== null);

        if (array_key_exists('history_visible_to_new_members', $data)) {
            $attributes['history_visible_to_new_members'] = (bool) $data['history_visible_to_new_members'];
        }

        $conversation->forceFill($attributes)->save();

        $this->events->conversationUpdated($conversation, 'profile_updated');

        return $conversation;
    }

    /**
     * @param  array<int, string>  $userIds
     */
    public function addMembers(User $actor, Conversation $conversation, array $userIds): Conversation
    {
        $this->assertGroup($conversation);
        $this->assertCanManage($actor, $conversation);

        $ids = collect($userIds)->unique()->values()->all();

        $users = $this->activeUsers($ids);

        foreach ($users as $user) {
            if ($this->contacts->hasBlockBetween($actor, $user)) {
                throw ValidationException::withMessages([
                    'user_ids' => __('You cannot add a user you have blocked or who blocked you.'),
                ]);
            }
        }

        $existing = DB::table('conversation_members')
            ->where('conversation_id', $conversation->id)
            ->whereIn('user_id', $ids)
            ->get()
            ->keyBy('user_id');

        $toAdd = $users
            ->reject(fn (User $user): bool => isset($existing[$user->id]) && $existing[$user->id]->left_at === null)
            ->values();

        if ($toAdd->isEmpty()) {
            return $conversation;
        }

        $activeCount = DB::table('conversation_members')
            ->where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->count();

        if ($activeCount + $toAdd->count() > self::MAX_MEMBERS) {
            throw ValidationException::withMessages([
                'user_ids' => __('A group can have at most :count members.', ['count' => self::MAX_MEMBERS]),
            ]);
        }

        $historyFromSeq = $this->historyFromSeqFor($conversation);

        DB::transaction(function () use ($conversation, $toAdd, $existing, $historyFromSeq): void {
            $now = Carbon::now();

            foreach ($toAdd as $user) {
                if (isset($existing[$user->id])) {
                    // Rejoining resets the read cursor and the personal mute so
                    // the member starts from the current history window.
                    DB::table('conversation_members')
                        ->where('conversation_id', $conversation->id)
                        ->where('user_id', $user->id)
                        ->update([
                            'role' => ConversationRole::Member->value,
                            'joined_at' => $now,
                            'left_at' => null,
                            'last_read_seq' => 0,
                            'history_from_seq' => $historyFromSeq,
                            'muted_until' => null,
                            'archived_at' => null,
                            'updated_at' => $now,
                        ]);

                    continue;
                }

                $conversation->members()->attach($user->id, [
                    'role' => ConversationRole::Member->value,
                    'joined_at' => $now,
                    'history_from_seq' => $historyFromSeq,
                ]);
            }

            $this->events->conversationUpdated($conversation, 'member_added');
        });

        $this->events->conversationChangedForUsers(
            $conversation,
            $toAdd->map(fn (User $user): string => (string) $user->id)->all(),
            'member_added',
        );

        return $conversation;
    }

    public function removeMember(User $actor, Conversation $conversation, User $target): void
    {
        $this->assertGroup($conversation);
        $this->assertCanManage($actor, $conversation);

        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'user' => __('Use the leave endpoint to remove yourself.'),
            ]);
        }

        $actorRole = $this->roleOf($conversation, $actor);
        $targetRole = $this->activeRoleOf($conversation, $target);

        if ($targetRole === null) {
            throw ValidationException::withMessages([
                'user' => __('This user is not a member of the group.'),
            ]);
        }

        if ($targetRole === ConversationRole::Owner) {
            throw ValidationException::withMessages([
                'user' => __('The group owner cannot be removed.'),
            ]);
        }

        if ($targetRole->rank() >= $actorRole->rank()) {
            throw ValidationException::withMessages([
                'user' => __('You cannot remove a member with an equal or higher role.'),
            ]);
        }

        DB::transaction(function () use ($conversation, $target): void {
            DB::table('conversation_members')
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $target->id)
                ->update([
                    'left_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            $this->events->conversationRemoved($conversation, (string) $target->id);
            $this->events->conversationUpdated($conversation, 'member_removed');
        });
    }

    /**
     * Leave a group, handing ownership over (or deleting an empty group).
     */
    public function leave(User $actor, Conversation $conversation): void
    {
        $this->assertGroup($conversation);

        $role = $this->activeRoleOf($conversation, $actor);

        if ($role === null) {
            throw ValidationException::withMessages([
                'conversation' => __('You are not a member of this group.'),
            ]);
        }

        if ($role === ConversationRole::Owner) {
            $this->leaveAsOwner($actor, $conversation);

            return;
        }

        DB::transaction(function () use ($conversation, $actor): void {
            DB::table('conversation_members')
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $actor->id)
                ->update([
                    'left_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            $this->events->conversationRemoved($conversation, (string) $actor->id);
            $this->events->conversationUpdated($conversation, 'member_left');
        });
    }

    public function changeRole(User $actor, Conversation $conversation, User $target, ConversationRole $role): void
    {
        $this->assertGroup($conversation);

        if (! $this->roleOf($conversation, $actor)->canAssignRoles()) {
            throw ValidationException::withMessages([
                'role' => __('Only the group owner can change roles.'),
            ]);
        }

        if (! in_array($role, ConversationRole::assignable(), true)) {
            throw ValidationException::withMessages([
                'role' => __('This role cannot be assigned.'),
            ]);
        }

        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'role' => __('You cannot change your own role.'),
            ]);
        }

        if ($this->activeRoleOf($conversation, $target) === null) {
            throw ValidationException::withMessages([
                'user' => __('This user is not a member of the group.'),
            ]);
        }

        DB::transaction(function () use ($conversation, $target, $role): void {
            DB::table('conversation_members')
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $target->id)
                ->update([
                    'role' => $role->value,
                    'updated_at' => Carbon::now(),
                ]);

            $this->events->conversationUpdated($conversation, 'roles_changed');
            $this->events->conversationChangedForUsers(
                $conversation,
                [(string) $target->id],
                'roles_changed',
            );
        });
    }

    public function createInvite(
        User $actor,
        Conversation $conversation,
        ?Carbon $expiresAt = null,
        ?int $maxUses = null,
    ): ConversationInvite {
        $this->assertGroup($conversation);
        $this->assertCanManage($actor, $conversation);

        return ConversationInvite::query()->create([
            'conversation_id' => $conversation->id,
            'created_by' => $actor->id,
            'token' => $this->uniqueToken(),
            'expires_at' => $expiresAt,
            'max_uses' => $maxUses,
        ]);
    }

    public function revokeInvite(User $actor, Conversation $conversation, ConversationInvite $invite): ConversationInvite
    {
        $this->assertGroup($conversation);
        $this->assertCanManage($actor, $conversation);

        if ($invite->conversation_id !== $conversation->id) {
            throw (new ModelNotFoundException)->setModel(ConversationInvite::class, [$invite->id]);
        }

        if (! $invite->isRevoked()) {
            $invite->forceFill(['revoked_at' => Carbon::now()])->save();
        }

        return $invite;
    }

    public function preview(string $token): ConversationInvite
    {
        $invite = ConversationInvite::query()
            ->with('conversation')
            ->where('token', $token)
            ->first();

        if ($invite === null) {
            throw ValidationException::withMessages([
                'token' => __('This invite link is not valid.'),
            ]);
        }

        return $invite;
    }

    /**
     * Join through an invite link. Redeeming twice is a no-op for members, so a
     * user who taps the link again simply lands in the group.
     */
    public function redeem(User $actor, string $token): Conversation
    {
        $invite = $this->preview($token);

        if (! $invite->isUsable()) {
            throw ValidationException::withMessages([
                'token' => __('This invite link can no longer be used.'),
            ]);
        }

        $conversation = $invite->conversation;

        if (! $conversation->isGroup()) {
            throw ValidationException::withMessages([
                'token' => __('This invite link is not valid.'),
            ]);
        }

        if ($this->activeRoleOf($conversation, $actor) !== null) {
            return $conversation;
        }

        $activeCount = DB::table('conversation_members')
            ->where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->count();

        if ($activeCount >= self::MAX_MEMBERS) {
            throw ValidationException::withMessages([
                'token' => __('This group is full.'),
            ]);
        }

        $historyFromSeq = $this->historyFromSeqFor($conversation);

        DB::transaction(function () use ($conversation, $invite, $actor, $historyFromSeq): void {
            $now = Carbon::now();

            $existing = DB::table('conversation_members')
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $actor->id)
                ->exists();

            if ($existing) {
                DB::table('conversation_members')
                    ->where('conversation_id', $conversation->id)
                    ->where('user_id', $actor->id)
                    ->update([
                        'role' => ConversationRole::Member->value,
                        'joined_at' => $now,
                        'left_at' => null,
                        'last_read_seq' => 0,
                        'history_from_seq' => $historyFromSeq,
                        'muted_until' => null,
                        'archived_at' => null,
                        'updated_at' => $now,
                    ]);
            } else {
                $conversation->members()->attach($actor->id, [
                    'role' => ConversationRole::Member->value,
                    'joined_at' => $now,
                    'history_from_seq' => $historyFromSeq,
                ]);
            }

            DB::table('conversation_invites')
                ->where('id', $invite->id)
                ->increment('uses');

            $this->events->conversationUpdated($conversation, 'member_added');
        });

        return $conversation;
    }

    /**
     * Active members of a group, owner first, then by privilege and name.
     *
     * @return Collection<int, User>
     */
    public function members(Conversation $conversation): Collection
    {
        return $conversation->members()
            ->whereNull('conversation_members.left_at')
            ->orderByRaw("CASE conversation_members.role
                WHEN 'owner' THEN 0
                WHEN 'admin' THEN 1
                WHEN 'moderator' THEN 2
                ELSE 3 END")
            ->orderBy('users.name')
            ->get();
    }

    /**
     * First sequence number a member joining right now may read.
     *
     * `next_message_seq` is the last sequence handed out, so the next message
     * will be one higher. Groups that share history start at zero.
     */
    private function historyFromSeqFor(Conversation $conversation): int
    {
        return $conversation->sharesHistoryWithNewMembers()
            ? 0
            : (int) $conversation->next_message_seq + 1;
    }

    /**
     * @param  array<int, string>  $ids
     * @return Collection<int, User>
     */
    private function activeUsers(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $users = User::query()
            ->whereIn('id', $ids)
            ->where('status', AccountStatus::Active)
            ->get();

        if ($users->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'member_ids' => __('One or more selected users are not available.'),
            ]);
        }

        return $users;
    }

    private function leaveAsOwner(User $actor, Conversation $conversation): void
    {
        $successor = DB::table('conversation_members')
            ->where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->where('user_id', '!=', $actor->id)
            ->orderBy('joined_at')
            ->orderBy('user_id')
            ->value('user_id');

        if ($successor === null) {
            // Last member out: the group has no one to hand over to, and an
            // ownerless group would be unmanageable, so it is removed.
            DB::transaction(function () use ($conversation, $actor): void {
                $this->events->conversationRemoved($conversation, (string) $actor->id);
                $conversation->delete();
            });

            return;
        }

        DB::transaction(function () use ($conversation, $actor, $successor): void {
            DB::table('conversation_members')
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $successor)
                ->update([
                    'role' => ConversationRole::Owner->value,
                    'updated_at' => Carbon::now(),
                ]);

            DB::table('conversation_members')
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $actor->id)
                ->update([
                    'left_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

            DB::table('conversations')
                ->where('id', $conversation->id)
                ->update(['created_by' => $successor]);

            $this->events->conversationRemoved($conversation, (string) $actor->id);
            $this->events->conversationUpdated($conversation, 'owner_changed');
            $this->events->conversationChangedForUsers(
                $conversation,
                [(string) $successor],
                'owner_changed',
            );
        });
    }

    private function assertGroup(Conversation $conversation): void
    {
        if (! $conversation->isGroup()) {
            throw ValidationException::withMessages([
                'conversation' => __('This conversation is not a group.'),
            ]);
        }
    }

    private function assertCanManage(User $actor, Conversation $conversation): void
    {
        if (! $this->roleOf($conversation, $actor)->canManageGroup()) {
            throw ValidationException::withMessages([
                'conversation' => __('You are not allowed to manage this group.'),
            ]);
        }
    }

    private function roleOf(Conversation $conversation, User $user): ConversationRole
    {
        $role = $this->activeRoleOf($conversation, $user);

        if ($role === null) {
            throw ValidationException::withMessages([
                'conversation' => __('You are not a member of this group.'),
            ]);
        }

        return $role;
    }

    private function activeRoleOf(Conversation $conversation, User $user): ?ConversationRole
    {
        $role = DB::table('conversation_members')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->value('role');

        return $role !== null ? ConversationRole::from((string) $role) : null;
    }

    private function uniqueToken(): string
    {
        do {
            $token = ConversationInvite::generateToken();
        } while (ConversationInvite::query()->where('token', $token)->exists());

        return $token;
    }
}
