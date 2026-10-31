<?php

namespace App\Services;

use App\Models\User;

/**
 * Attaches the presence the gateway knows about to user models.
 *
 * Presence is not persisted in PostgreSQL: it is derived from the gateway's
 * connection leases, which expire on their own. Resources therefore read the
 * annotated attributes, allowing one Redis round trip per response instead of
 * one per member.
 */
class PresenceService
{
    public function __construct(private readonly PresenceStore $store) {}

    /**
     * @return array{online: bool, last_seen_at: string|null}
     */
    public function for(User $user): array
    {
        return $this->snapshot([$user->id])[$user->id] ?? [
            'online' => false,
            'last_seen_at' => null,
        ];
    }

    /**
     * @param  array<int, string>  $userIds
     * @return array<string, array{online: bool, last_seen_at: string|null}>
     */
    public function snapshot(array $userIds): array
    {
        return $this->store->snapshot($userIds);
    }

    /**
     * Copy presence onto the models so resources can render it without
     * another lookup.
     *
     * @param  iterable<int, User>  $users
     */
    public function annotate(iterable $users): void
    {
        $models = [];

        foreach ($users as $user) {
            if ($user instanceof User) {
                $models[$user->id] = $user;
            }
        }

        if ($models === []) {
            return;
        }

        foreach ($this->store->snapshot(array_keys($models)) as $userId => $presence) {
            $models[$userId]->setAttribute('is_online', (bool) $presence['online']);
            $models[$userId]->setAttribute('presence_last_seen_at', $presence['last_seen_at']);
        }
    }
}
