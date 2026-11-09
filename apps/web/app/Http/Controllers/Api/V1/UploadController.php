<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PresignUploadRequest;
use App\Http\Resources\MessageAttachmentResource;
use App\Models\MessageAttachment;
use App\Services\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    /**
     * Reserve a storage key and hand the client a short-lived signed URL to
     * PUT the bytes to. The row stays pending until the bytes arrive and is
     * only linked to a message when that message is sent.
     */
    public function presign(
        PresignUploadRequest $request,
        UploadService $uploads,
    ): JsonResponse {
        $validated = $request->validated();

        $attachment = $uploads->presign($request->user(), [
            'original_name' => (string) $validated['original_name'],
            'mime_type' => (string) $validated['mime_type'],
            'size_bytes' => (int) $validated['size_bytes'],
            'is_encrypted' => (bool) ($validated['is_encrypted'] ?? false),
            'conversation_id' => $validated['conversation_id'] ?? null,
            'key_version' => isset($validated['key_version'])
                ? (int) $validated['key_version']
                : null,
            'iv' => $validated['iv'] ?? null,
            'key_iv' => $validated['key_iv'] ?? null,
            'key_ciphertext' => $validated['key_ciphertext'] ?? null,
        ]);

        return (new MessageAttachmentResource($attachment))
            ->additional([
                'upload' => [
                    'method' => 'PUT',
                    'url' => $uploads->uploadUrl($attachment),
                    'expires_at' => $attachment->expires_at?->toIso8601String(),
                ],
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Receive the presigned upload. This endpoint is authenticated by the
     * signature in the URL rather than by the session, which is what makes
     * the URL safe to hand to a browser upload.
     */
    public function receive(
        Request $request,
        MessageAttachment $upload,
        UploadService $uploads,
    ): JsonResponse {
        abort_unless($request->hasValidSignature(), 403);

        $declaredLength = (int) $upload->size_bytes;
        $maxBytes = (int) config('chat.media.max_bytes');

        $contentLength = $request->server('CONTENT_LENGTH');

        if ($contentLength !== null && (int) $contentLength > min($declaredLength, $maxBytes)) {
            abort(413, 'The uploaded file is larger than declared.');
        }

        $attachment = $uploads->receive($upload, $request->getContent());

        return (new MessageAttachmentResource($attachment))
            ->additional(['message' => __('The file has been uploaded.')])
            ->response();
    }
}
