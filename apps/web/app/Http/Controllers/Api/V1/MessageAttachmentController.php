<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\UploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authorized byte delivery for attachments. Nothing is ever exposed through
 * a public storage URL: every download re-checks conversation membership.
 */
class MessageAttachmentController extends Controller
{
    public function download(
        Request $request,
        Message $message,
        MessageAttachment $attachment,
        UploadService $uploads,
    ): StreamedResponse {
        $this->authorize('view', $message);

        abort_unless($attachment->message_id === $message->id, 404);

        // A deleted message keeps its rows for the retention window, but the
        // bytes stop being reachable immediately.
        abort_if($message->deleted_at !== null, 404);

        abort_unless($attachment->isSendable(), 404);

        $key = $attachment->storage_key;

        if ($request->query('size') === 'thumb' && $attachment->thumbnail_key !== null) {
            $key = $attachment->thumbnail_key;
        }

        $disk = Storage::disk($uploads->disk());

        abort_unless($disk->exists($key), 404);

        // Media that only renders as a document is served as a download;
        // images/video/audio stay inline so bubbles can preview them.
        // Encrypted rows are ciphertext: the browser decrypts them before
        // rendering, so they must never be served inline as their media type.
        $disposition = ! $attachment->is_encrypted
            && ($attachment->isImage() || $attachment->isVideo() || $attachment->isAudio())
                ? 'inline'
                : 'attachment';
        $contentType = $attachment->is_encrypted
            ? 'application/octet-stream'
            : $attachment->mime_type;

        return $disk->response(
            $key,
            $this->safeFilename($attachment),
            [
                'Content-Type' => $contentType,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Cache-Control' => 'private, max-age=3600',
            ],
            $disposition,
        );
    }

    /**
     * Strip anything that could break out of the Content-Disposition header.
     */
    private function safeFilename(MessageAttachment $attachment): string
    {
        $name = str_replace(["\r", "\n", '"', '\\'], '', $attachment->original_name);
        $name = trim($name);

        return $name !== '' ? $name : 'attachment';
    }
}
