<?php

namespace App\Enums;

/**
 * Group roles, ordered from the most to the least privileged.
 *
 * Direct conversations store `member` for both participants, so the capability
 * helpers below are only meaningful for group conversations.
 */
enum ConversationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Moderator = 'moderator';
    case Member = 'member';

    /**
     * Ranking used to decide whether an actor outranks the target of a
     * moderation action. Higher wins.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 4,
            self::Admin => 3,
            self::Moderator => 2,
            self::Member => 1,
        };
    }

    /**
     * Owners and admins can change the member list and the group profile.
     */
    public function canManageGroup(): bool
    {
        return $this->rank() >= self::Admin->rank();
    }

    /**
     * Every group manager plus moderators can pin messages.
     */
    public function canModerate(): bool
    {
        return $this->rank() >= self::Moderator->rank();
    }

    /**
     * Only the owner may promote or demote other members.
     */
    public function canAssignRoles(): bool
    {
        return $this === self::Owner;
    }

    /**
     * @return array<int, self>
     */
    public static function assignable(): array
    {
        return [self::Admin, self::Moderator, self::Member];
    }
}
