<?php

namespace App\Services;

/**
 * Presence store used when realtime presence is disabled or unavailable.
 */
class NullPresenceStore implements PresenceStore
{
    public function snapshot(array $userIds): array
    {
        return [];
    }
}
