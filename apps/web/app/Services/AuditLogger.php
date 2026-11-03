<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the append-only audit trail for administrative actions.
 *
 * Callers pass a stable action string (for example `user.suspended`) and
 * optional metadata; the actor, request IP and user agent are captured here so
 * every entry records the same context.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?User $actor,
        ?Model $subject = null,
        array $metadata = [],
    ): AuditLog {
        $request = app()->bound('request') ? request() : null;

        return AuditLog::query()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
