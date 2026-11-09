<?php

namespace App\Models;

use App\Enums\AttachmentScanStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One uploaded file attached to (or pending on) a message.
 *
 * Rows are created by the presign endpoint before any bytes arrive, filled in
 * by the signed upload endpoint, and linked to their message when the message
 * is sent. `message_id IS NULL` therefore means "pending or orphaned upload".
 *
 * @property string $id
 * @property string|null $message_id
 * @property string|null $uploader_id
 * @property string $storage_key
 * @property string|null $thumbnail_key
 * @property string $original_name
 * @property string $mime_type
 * @property bool $is_encrypted
 * @property string|null $conversation_id
 * @property int|null $key_version
 * @property string|null $iv
 * @property string|null $key_iv
 * @property string|null $key_ciphertext
 * @property int $size_bytes
 * @property Carbon|null $uploaded_at
 * @property AttachmentScanStatus $scan_status
 * @property Carbon|null $scanned_at
 * @property Carbon|null $expires_at
 * @property int|null $width
 * @property int|null $height
 * @property int|null $duration_ms
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Message|null $message
 * @property-read User|null $uploader
 */
#[Fillable([
    'message_id',
    'uploader_id',
    'storage_key',
    'thumbnail_key',
    'original_name',
    'mime_type',
    'is_encrypted',
    'conversation_id',
    'key_version',
    'iv',
    'key_iv',
    'key_ciphertext',
    'size_bytes',
    'uploaded_at',
    'scan_status',
    'scanned_at',
    'expires_at',
    'width',
    'height',
    'duration_ms',
])]
class MessageAttachment extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'is_encrypted' => 'boolean',
            'key_version' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
            'uploaded_at' => 'datetime',
            'scan_status' => AttachmentScanStatus::class,
            'scanned_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Whether the stored bytes passed (or were excused from) malware scanning
     * and may therefore be sent, previewed, or downloaded.
     */
    public function isSendable(): bool
    {
        return $this->scan_status->isSendable();
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }

    public function isAudio(): bool
    {
        return str_starts_with($this->mime_type, 'audio/');
    }

    /**
     * Coarse kind used by clients to pick a renderer.
     */
    public function kind(): string
    {
        return match (true) {
            $this->isImage() => 'image',
            $this->isVideo() => 'video',
            $this->isAudio() => 'audio',
            default => 'file',
        };
    }
}
