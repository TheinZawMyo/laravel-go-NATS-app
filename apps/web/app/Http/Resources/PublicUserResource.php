<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class PublicUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $relationship = $user instanceof User ? $user->getAttribute('relationship') : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar_url' => $this->avatar_url,
            'bio' => $this->bio,
            'status_text' => $this->status_text,
            // Presence comes from the gateway's connection leases; the column
            // is the fallback for users that have no live lease.
            'online' => (bool) ($this->is_online ?? false),
            'last_seen_at' => $this->presence_last_seen_at ?? $this->last_seen_at,
            // Only user search fills this in, so it stays out of every other
            // user representation.
            'relationship' => $this->when(
                is_string($relationship),
                fn (): string => $relationship,
            ),
        ];
    }
}
