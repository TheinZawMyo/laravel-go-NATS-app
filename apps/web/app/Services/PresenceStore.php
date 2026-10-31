<?php

namespace App\Services;

/**
 * Read access to the presence leases the gateway keeps in Redis.
 */
interface PresenceStore
{
    /**
     * Read the presence of the given users.
     *
     * Missing users are reported offline so callers never have to tell
     * "unknown" and "offline" apart.
     *
     * @param  array<int, string>  $userIds
     * @return array<string, array{online: bool, last_seen_at: string|null}>
     */
    public function snapshot(array $userIds): array;
}
