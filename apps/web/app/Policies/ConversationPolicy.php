<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $actor, Conversation $conversation): bool
    {
        return $conversation->hasActiveMember($actor);
    }

    public function send(User $actor, Conversation $conversation): bool
    {
        return $conversation->hasActiveMember($actor);
    }

    public function read(User $actor, Conversation $conversation): bool
    {
        return $conversation->hasActiveMember($actor);
    }

    /**
     * Owners and admins may edit the group profile, add members and manage
     * invite links.
     */
    public function manage(User $actor, Conversation $conversation): bool
    {
        return $this->view($actor, $conversation)
            && $conversation->isGroup()
            && $conversation->membershipRoleFor($actor)?->canManageGroup() === true;
    }

    /**
     * Direct conversations are pinned by either participant; groups need a
     * moderator or higher.
     */
    public function pin(User $actor, Conversation $conversation): bool
    {
        if (! $this->view($actor, $conversation)) {
            return false;
        }

        if (! $conversation->isGroup()) {
            return true;
        }

        return $conversation->membershipRoleFor($actor)?->canModerate() === true;
    }

    /**
     * Only the owner hands out roles.
     */
    public function assignRoles(User $actor, Conversation $conversation): bool
    {
        return $this->view($actor, $conversation)
            && $conversation->isGroup()
            && $conversation->membershipRoleFor($actor)?->canAssignRoles() === true;
    }

    /**
     * Leaving is available to every member of a group.
     */
    public function leave(User $actor, Conversation $conversation): bool
    {
        return $this->manage($actor, $conversation) || $this->view($actor, $conversation);
    }
}
