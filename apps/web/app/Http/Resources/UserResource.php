<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'bio' => $this->bio,
            'status_text' => $this->status_text,
            'status' => $this->status->value,
            'is_admin' => $this->isAdmin(),
            'last_seen_visibility' => $this->last_seen_visibility->value,
            'profile_visibility' => $this->profile_visibility->value,
            'contact_requests_policy' => $this->contact_requests_policy->value,
            'translation_language' => $this->translation_language,
            'email_verified_at' => $this->email_verified_at,
            'last_seen_at' => $this->last_seen_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
