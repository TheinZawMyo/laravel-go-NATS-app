<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A message plus just enough conversation context for a search result to be
 * actionable without loading the whole conversation representation.
 *
 * @mixin Message
 */
class MessageSearchResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $conversation = $this->conversation;
        $viewer = $request->user();
        $other = $viewer !== null ? $conversation->otherParticipant($viewer) : null;

        return [
            ...(new MessageResource($this->resource))->resolve($request),
            'conversation' => [
                'id' => $conversation->id,
                'type' => $conversation->type->value,
                'title' => $conversation->title ?? $other?->name,
            ],
        ];
    }
}
