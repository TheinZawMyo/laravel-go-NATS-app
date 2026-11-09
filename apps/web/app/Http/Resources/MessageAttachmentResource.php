<?php

namespace App\Http\Resources;

use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * @mixin MessageAttachment
 */
class MessageAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $messageId = $this->message_id;

        $downloadUrl = $messageId !== null
            ? URL::route('api.v1.attachments.download', [
                'message' => $messageId,
                'attachment' => $this->id,
            ])
            : null;

        $thumbnailUrl = $downloadUrl !== null && $this->thumbnail_key !== null
            ? URL::route('api.v1.attachments.download', [
                'message' => $messageId,
                'attachment' => $this->id,
                'size' => 'thumb',
            ])
            : null;

        return [
            'id' => $this->id,
            'message_id' => $messageId,
            // Only present on gallery rows, where the query aliases the
            // message sequence in so the client can page with it.
            'message_seq' => $this->whenHas('message_seq'),
            'name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'is_encrypted' => (bool) $this->is_encrypted,
            'key_version' => $this->key_version !== null ? (int) $this->key_version : null,
            'iv' => $this->iv,
            'key_iv' => $this->key_iv,
            'key_ciphertext' => $this->key_ciphertext,
            'kind' => $this->kind(),
            'size_bytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'duration_ms' => $this->duration_ms,
            'url' => $downloadUrl,
            'thumbnail_url' => $thumbnailUrl,
            'created_at' => $this->created_at,
        ];
    }
}
