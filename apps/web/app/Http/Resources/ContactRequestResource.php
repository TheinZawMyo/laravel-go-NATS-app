<?php

namespace App\Http\Resources;

use App\Models\ContactRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContactRequest
 */
class ContactRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        $direction = match (true) {
            $viewer === null => null,
            $this->sender_id === $viewer->id => 'outgoing',
            $this->receiver_id === $viewer->id => 'incoming',
            default => null,
        };

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'message' => $this->message,
            'direction' => $direction,
            'sender' => (new PublicUserResource($this->sender))->resolve($request),
            'receiver' => (new PublicUserResource($this->receiver))->resolve($request),
            'responded_at' => $this->responded_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
