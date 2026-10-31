<?php

namespace App\Http\Resources;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        /** @var Collection<int, User> $members */
        $members = $this->relationLoaded('members')
            ? $this->members
            : collect();

        $participant = $this->type === ConversationType::Direct && $viewer !== null
            ? $this->otherParticipant($viewer)
            : null;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->title,
            'avatar_url' => $this->avatar_key !== null
                ? Storage::disk('public')->url($this->avatar_key)
                : null,
            'unread_count' => (int) ($this->unread_count ?? 0),
            'last_read_seq' => (int) ($this->viewer_last_read_seq ?? 0),
            'next_message_seq' => (int) $this->next_message_seq,
            'participant' => $participant !== null
                ? (new PublicUserResource($participant))->resolve($request)
                : null,
            'last_message' => $this->whenLoaded(
                'lastMessage',
                fn () => $this->lastMessage !== null
                    ? (new MessageResource($this->lastMessage))->resolve($request)
                    : null,
            ),
            'members' => $members
                ->map(fn (User $member): array => [
                    'role' => $member->pivot->role->value,
                    'last_read_seq' => (int) $member->pivot->last_read_seq,
                    'user' => (new PublicUserResource($member))->resolve($request),
                ])
                ->values()
                ->all(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
