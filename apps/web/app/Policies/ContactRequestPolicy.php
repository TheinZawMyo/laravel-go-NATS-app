<?php

namespace App\Policies;

use App\Models\ContactRequest;
use App\Models\User;

class ContactRequestPolicy
{
    public function view(User $actor, ContactRequest $request): bool
    {
        return $this->isParticipant($actor, $request);
    }

    /**
     * Only the receiver accepts or rejects.
     */
    public function respond(User $actor, ContactRequest $request): bool
    {
        return $request->receiver_id === $actor->id;
    }

    /**
     * Only the sender withdraws their own request.
     */
    public function cancel(User $actor, ContactRequest $request): bool
    {
        return $request->sender_id === $actor->id;
    }

    private function isParticipant(User $actor, ContactRequest $request): bool
    {
        return $request->sender_id === $actor->id
            || $request->receiver_id === $actor->id;
    }
}
