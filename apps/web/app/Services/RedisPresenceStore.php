<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the presence leases the gateway writes to Redis.
 *
 * The gateway owns this state: it keeps a set of live websocket connection ids
 * per user and refreshes the key's expiry from the heartbeat, so a crashed
 * gateway cannot pin a user online forever. This class is read-only and best
 * effort - when Redis is unreachable everyone is reported offline instead of
 * failing the request.
 */
class RedisPresenceStore implements PresenceStore
{
    public const CONNECTIONS_KEY = 'presence:user:';

    public const LAST_SEEN_KEY = 'presence:lastseen:';

    public function __construct(private readonly Connection $connection) {}

    public function snapshot(array $userIds): array
    {
        $userIds = $this->normalize($userIds);

        if ($userIds === []) {
            return [];
        }

        $presence = array_fill_keys($userIds, ['online' => false, 'last_seen_at' => null]);

        try {
            $results = $this->connection->pipeline(function ($pipeline) use ($userIds): void {
                foreach ($userIds as $userId) {
                    $pipeline->scard(self::CONNECTIONS_KEY.$userId);
                    $pipeline->get(self::LAST_SEEN_KEY.$userId);
                }
            });
        } catch (Throwable $exception) {
            Log::warning('Unable to read presence leases from Redis.', [
                'exception' => $exception,
            ]);

            return $presence;
        }

        foreach (array_values($userIds) as $index => $userId) {
            $presence[$userId] = [
                'online' => (int) ($results[$index * 2] ?? 0) > 0,
                'last_seen_at' => $this->lastSeenAt($results[$index * 2 + 1] ?? null),
            ];
        }

        return $presence;
    }

    /**
     * @param  array<int, string>  $userIds
     * @return array<int, string>
     */
    private function normalize(array $userIds): array
    {
        return array_values(array_unique(array_filter(
            $userIds,
            fn (mixed $userId): bool => is_string($userId) && $userId !== '',
        )));
    }

    private function lastSeenAt(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
