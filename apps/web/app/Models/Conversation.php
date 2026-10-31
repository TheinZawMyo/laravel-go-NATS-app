<?php

namespace App\Models;

use App\Enums\ConversationRole;
use App\Enums\ConversationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property ConversationType $type
 * @property string|null $title
 * @property string|null $avatar_key
 * @property string $created_by
 * @property string|null $direct_pair_key
 * @property string|null $last_message_id
 * @property int $next_message_seq
 * @property-read Message|null $lastMessage
 */
#[Fillable(['type', 'title', 'avatar_key', 'created_by', 'direct_pair_key', 'last_message_id', 'next_message_seq'])]
class Conversation extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'next_message_seq' => 'integer',
        ];
    }

    /**
     * Build the canonical key that prevents duplicate direct conversations.
     */
    public static function directPairKey(string $firstUserId, string $secondUserId): string
    {
        $ids = [$firstUserId, $secondUserId];
        sort($ids, SORT_STRING);

        return implode(':', $ids);
    }

    /**
     * @return BelongsToMany<User, $this, ConversationMember>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_members')
            ->using(ConversationMember::class)
            ->withPivot(['role', 'joined_at', 'left_at', 'last_read_seq', 'muted_until', 'archived_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function hasActiveMember(User $user): bool
    {
        if ($this->relationLoaded('members')) {
            return $this->members->contains(
                fn (User $member): bool => $member->id === $user->id && $member->pivot->left_at === null,
            );
        }

        return $this->members()
            ->whereKey($user->id)
            ->whereNull('conversation_members.left_at')
            ->exists();
    }

    public function membershipRoleFor(User $user): ?ConversationRole
    {
        $members = $this->relationLoaded('members')
            ? $this->members
            : $this->members()->get();

        $member = $members->firstWhere('id', $user->id);

        return $member?->pivot?->role;
    }

    public function otherParticipant(User $user): ?User
    {
        $members = $this->relationLoaded('members')
            ? $this->members
            : $this->members()->get();

        return $members->first(fn (User $member): bool => $member->id !== $user->id);
    }
}
