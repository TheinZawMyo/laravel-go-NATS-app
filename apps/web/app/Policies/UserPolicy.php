<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, User $user): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->id === $user->id;
    }

    public function delete(User $actor, User $user): bool
    {
        return $actor->id === $user->id;
    }

    /**
     * Blocking is always allowed except against yourself; the ContactService
     * owns the rest of the rules (idempotency, contact removal).
     */
    public function block(User $actor, User $user): bool
    {
        return $actor->id !== $user->id;
    }
}
