<?php

namespace App\Http\Resources;

use App\Models\MessagePin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MessagePin
 */
class MessagePinResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'conversation_id' => $this->conversation_id,
            'message' => (new MessageResource($this->message))->resolve($request),
            'pinned_by' => (new PublicUserResource($this->pinnedBy))->resolve($request),
            'pinned_at' => $this->pinned_at,
        ];
    }
}
