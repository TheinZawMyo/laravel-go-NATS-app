<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'metadata' => $this->metadata,
            'ip_address' => $this->ip_address,
            'actor' => $this->whenLoaded(
                'actor',
                fn () => $this->actor !== null
                    ? [
                        'id' => $this->actor->id,
                        'name' => $this->actor->name,
                        'username' => $this->actor->username,
                    ]
                    : null,
            ),
            'created_at' => $this->created_at,
        ];
    }
}
