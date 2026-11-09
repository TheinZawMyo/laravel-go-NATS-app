<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'seq' => $this->seq,
            'client_message_id' => $this->client_message_id,
            'sender' => (new PublicUserResource($this->sender))->resolve($request),
            'type' => $this->type->value,
            'is_encrypted' => (bool) $this->is_encrypted,
            'body' => $this->deleted_at === null ? $this->body : null,
            'encryption' => $this->deleted_at === null ? $this->encryption : null,
            'attachments' => $this->deleted_at === null
                ? MessageAttachmentResource::collection($this->resource->attachments)
                    ->resolve($request)
                : [],
            'reply_to' => $this->whenLoaded(
                'replyTo',
                fn () => $this->replyTo !== null
                    ? (new self($this->replyTo))->resolve($request)
                    : null,
            ),
            'edited_at' => $this->edited_at,
            'deleted_at' => $this->deleted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
