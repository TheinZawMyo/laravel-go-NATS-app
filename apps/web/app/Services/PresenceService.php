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
    public function __construct(
        private readonly PresenceStore $store,
        private readonly PrivacyService $privacy,
    ) {}

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
     * another lookup. When a viewer is supplied, the viewer's privacy rules
     * decide whether the timestamp is exposed.
     *
     * @param  iterable<int, User>  $users
     */
    public function annotate(iterable $users, ?User $viewer = null): void
    {
        $models = [];

        foreach ($users as $user) {
            $models[$user->id] = $user;
        }

        if ($models === []) {
            return;
        }

        $visible = $viewer !== null
            ? $this->privacy->lastSeenVisibleMap($viewer, array_values($models))
            : null;

        foreach ($this->store->snapshot(array_keys($models)) as $userId => $presence) {
            if (isset($models[$userId])) {
                $models[$userId]->setAttribute('is_online', (bool) $presence['online']);
                $models[$userId]->setAttribute('presence_last_seen_at', $presence['last_seen_at']);
            }
        }

        foreach ($models as $userId => $model) {
            $canSee = $visible === null || ($visible[$userId] ?? true);

            $model->setAttribute('presence_visible', $canSee);

            if (! $canSee) {
                $model->setAttribute('is_online', false);
                $model->setAttribute('presence_last_seen_at', null);
            }
        }
    }
}
