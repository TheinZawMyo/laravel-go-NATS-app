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
        // PresenceService sets this from the target's privacy settings. Without
        // an annotation (for example a freshly built resource in a test) the
        // safe default is to expose what was loaded.
        $presenceVisible = (bool) ($this->presence_visible ?? true);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar_url' => $this->avatar_url,
            'bio' => $this->bio,
            'status_text' => $this->status_text,
            // Presence comes from the gateway's connection leases; the column
            // is the fallback for users that have no live lease.
            'online' => $presenceVisible && (bool) ($this->is_online ?? false),
            'last_seen_at' => $presenceVisible
                ? ($this->presence_last_seen_at ?? $this->last_seen_at)
                : null,
            // Only user search fills this in, so it stays out of every other
            // user representation.
            'relationship' => $this->when(
                is_string($relationship),
                fn (): string => $relationship,
            ),
        ];
    }
}
