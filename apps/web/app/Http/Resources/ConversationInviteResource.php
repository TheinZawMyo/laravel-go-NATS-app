<?php

namespace App\Http\Resources;

use App\Models\ConversationInvite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin ConversationInvite
 */
class ConversationInviteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token' => $this->token,
            'url' => url('/join/'.$this->token),
            'expires_at' => $this->expires_at,
            'revoked_at' => $this->revoked_at,
            'max_uses' => $this->max_uses,
            'uses' => (int) $this->uses,
            'is_active' => $this->isUsable(),
            'created_at' => $this->created_at,
            'conversation' => $this->whenLoaded('conversation', function (): array {
                $conversation = $this->conversation;

                return [
                    'id' => $conversation->id,
                    'type' => $conversation->type->value,
                    'title' => $conversation->title,
                    'description' => $conversation->description,
                    'avatar_url' => $conversation->avatar_key !== null
                        ? Storage::disk('public')->url($conversation->avatar_key)
                        : null,
                    'member_count' => $conversation->members()
                        ->whereNull('conversation_members.left_at')
                        ->count(),
                ];
            }),
        ];
    }
}
