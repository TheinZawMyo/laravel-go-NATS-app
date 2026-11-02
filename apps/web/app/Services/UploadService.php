<?php

namespace App\Services;

use App\Enums\AttachmentScanStatus;
use App\Jobs\ProcessAttachment;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Presigned upload lifecycle: pending row → validated bytes on disk →
 * attached to a message.
 *
 * The client never chooses a storage path. Every key is generated here from
 * random material, and the bytes are re-validated against the declared
 * metadata — and scanned for malware — before they are written, so a presigned
 * URL cannot be abused to store arbitrary content under an arbitrary name.
 */
class UploadService
{
    public function __construct(
        private readonly MalwareScanner $scanner,
    ) {}

    /**
     * MIME types that a sniffer may legitimately disagree about because the
     * same container is used for several media kinds (m4a/mp4, mp3 tags, …).
     *
     * @var array<string, list<string>>
     */
    private const MIME_ALIASES = [
        'audio/mp4' => ['video/mp4', 'video/quicktime', 'audio/x-m4a', 'audio/m4a'],
        'audio/mpeg' => ['audio/mp3', 'audio/x-mpeg', 'application/octet-stream'],
        'audio/wav' => ['audio/x-wav', 'audio/vnd.wave', 'application/octet-stream'],
        'audio/ogg' => ['application/ogg', 'application/octet-stream'],
        'audio/webm' => ['video/webm', 'application/octet-stream'],
        'video/mp4' => ['video/quicktime', 'audio/mp4', 'application/octet-stream'],
        'video/quicktime' => ['video/mp4', 'application/octet-stream'],
        'video/webm' => ['audio/webm', 'application/octet-stream'],
        'image/jpeg' => ['image/jpg', 'image/pjpeg'],
        'image/png' => ['image/x-png'],
        'application/pdf' => ['application/x-pdf'],
        'text/plain' => ['text/x-c', 'text/x-csrc', 'text/x-chdr', 'text/x-java', 'text/x-php', 'application/octet-stream'],
        'text/csv' => ['text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'],
        'application/zip' => ['application/x-zip-compressed', 'application/octet-stream'],
        'application/x-7z-compressed' => ['application/octet-stream'],
        'application/gzip' => ['application/x-gzip', 'application/octet-stream'],
    ];

    /**
     * Types that must never be stored even if a client claims a safe MIME
     * type: they can execute script when the file is opened inline.
     *
     * @var list<string>
     */
    private const FORBIDDEN_DETECTED = [
        'text/html',
        'application/xhtml+xml',
        'image/svg+xml',
        'text/xml',
        'application/xml',
        'application/x-httpd-php',
        'text/x-php',
        'application/x-msdownload',
        'application/x-msdos-program',
        'application/x-executable',
        'application/x-sh',
    ];

    /**
     * Create the pending attachment row for a presigned upload.
     *
     * @param  array{original_name: string, mime_type: string, size_bytes: int}  $declared
     */
    public function presign(User $uploader, array $declared): MessageAttachment
    {
        $mimeType = strtolower($declared['mime_type']);
        $extension = $this->extensionFor($mimeType);

        $attachment = MessageAttachment::query()->create([
            'uploader_id' => $uploader->id,
            'storage_key' => sprintf(
                '%s/%s.%s',
                (string) Str::uuid(),
                Str::lower(Str::random(24)),
                $extension,
            ),
            'original_name' => $this->sanitizeName($declared['original_name']),
            'mime_type' => $mimeType,
            'size_bytes' => $declared['size_bytes'],
            'scan_status' => AttachmentScanStatus::Pending->value,
            'expires_at' => Carbon::now()->addMinutes(
                (int) config('chat.media.upload_ttl_minutes', 30),
            ),
        ]);

        return $attachment;
    }

    /**
     * The signed, short-lived URL the client PUTs the bytes to.
     */
    public function uploadUrl(MessageAttachment $attachment): string
    {
        return URL::temporarySignedRoute(
            'api.v1.uploads.receive',
            $attachment->expires_at ?? Carbon::now()->addMinutes(15),
            ['upload' => $attachment->id],
        );
    }

    /**
     * Validate the uploaded bytes against the declared metadata and store
     * them under the pre-generated key.
     *
     * @throws ValidationException when the bytes do not match the declaration
     */
    public function receive(MessageAttachment $attachment, string $contents): MessageAttachment
    {
        if ($attachment->message_id !== null || $attachment->uploaded_at !== null) {
            throw ValidationException::withMessages([
                'upload' => __('This upload has already been completed.'),
            ]);
        }

        if ($attachment->expires_at !== null && $attachment->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'upload' => __('This upload link has expired.'),
            ]);
        }

        $maxBytes = (int) config('chat.media.max_bytes');

        if ($attachment->size_bytes > $maxBytes) {
            throw ValidationException::withMessages([
                'upload' => __('This file is larger than the allowed maximum.'),
            ]);
        }

        $actualLength = strlen($contents);

        if ($actualLength !== $attachment->size_bytes) {
            throw ValidationException::withMessages([
                'upload' => __('The uploaded file does not match the declared size.'),
            ]);
        }

        $this->assertSafeBytes($attachment, $contents);

        // Scan before writing: a rejected file never reaches the disk at all,
        // which keeps the bucket free of known malware and makes cleanup moot.
        $scan = $this->scanner->scan($contents);
        $accepted = $this->acceptedScanStatus($scan);

        if ($accepted === null) {
            $attachment->forceFill([
                'scan_status' => $scan->status->value,
                'scanned_at' => Carbon::now(),
            ])->save();

            throw ValidationException::withMessages([
                'upload' => $scan->isInfected()
                    ? __('The file was rejected by the malware scanner.')
                    : __('The file could not be scanned, so it was rejected.'),
            ]);
        }

        $stored = Storage::disk($this->disk())->put($attachment->storage_key, $contents);

        if ($stored === false) {
            throw ValidationException::withMessages([
                'upload' => __('The file could not be stored.'),
            ]);
        }

        $attachment->forceFill([
            'uploaded_at' => Carbon::now(),
            'scan_status' => $accepted->value,
            'scanned_at' => Carbon::now(),
        ])->save();

        // Previews and duration metadata are best-effort extras, so they never
        // run inline with the upload response.
        if ($attachment->isImage() || $attachment->isVideo() || $attachment->isAudio()) {
            ProcessAttachment::dispatch($attachment->id);
        }

        return $attachment;
    }

    /**
     * Translate a scan result into the status to store, or null when the bytes
     * must be rejected. An unreachable scanner is either fail-closed (reject)
     * or fail-open (accept as unverified) depending on configuration.
     */
    private function acceptedScanStatus(MalwareScanResult $scan): ?AttachmentScanStatus
    {
        if ($scan->isInfected()) {
            return null;
        }

        if ($scan->hasFailed()) {
            $failClosed = (bool) config('chat.media.scan.fail_closed', false);

            return $failClosed ? null : AttachmentScanStatus::Skipped;
        }

        return $scan->status;
    }

    /**
     * Attach pending, uploaded rows to a message. Returns only the rows that
     * belong to the sender and are ready; the caller rejects on a mismatch.
     *
     * @param  list<string>  $attachmentIds
     * @return list<MessageAttachment>
     */
    public function claimForMessage(User $sender, array $attachmentIds): array
    {
        if ($attachmentIds === []) {
            return [];
        }

        $rows = MessageAttachment::query()
            ->whereKey($attachmentIds)
            ->where('uploader_id', $sender->id)
            ->whereNull('message_id')
            ->whereNotNull('uploaded_at')
            ->whereIn('scan_status', [
                AttachmentScanStatus::Clean->value,
                AttachmentScanStatus::Skipped->value,
            ])
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            })
            ->lockForUpdate()
            ->get();

        if ($rows->count() !== count(array_unique($attachmentIds))) {
            throw ValidationException::withMessages([
                'attachment_ids' => __('One or more attachments are not ready to send.'),
            ]);
        }

        return array_values($rows->all());
    }

    /**
     * Delete abandoned uploads (never sent, or past their expiry) together
     * with their bytes. Returns the number of removed rows.
     */
    public function pruneAbandoned(): int
    {
        $retentionCutoff = Carbon::now()->subHours(
            (int) config('chat.media.pending_retention_hours', 24),
        );

        $abandoned = MessageAttachment::query()
            ->whereNull('message_id')
            ->where(function ($query) use ($retentionCutoff): void {
                $query->where('expires_at', '<', Carbon::now())
                    ->orWhere('created_at', '<', $retentionCutoff);
            })
            ->get();

        return $this->purgeRows($abandoned);
    }

    /**
     * Delete attachments whose message was deleted, once the configured grace
     * period has passed. Message deletion is soft, so without this the bytes
     * would be served for a message nobody can see any more.
     *
     * Returns the number of removed rows.
     */
    public function purgeDeletedMessageAttachments(): int
    {
        $graceDays = max(0, (int) config('chat.media.deleted_message_grace_days', 7));
        $cutoff = Carbon::now()->subDays($graceDays);

        $rows = MessageAttachment::query()
            ->whereNotNull('message_id')
            ->whereHas('message', function ($query) use ($cutoff): void {
                $query->withTrashed()
                    ->whereNotNull('deleted_at')
                    ->where('deleted_at', '<=', $cutoff);
            })
            ->get();

        return $this->purgeRows($rows);
    }

    /**
     * Remove the bytes and rows for a set of attachments. The row is deleted
     * last so a storage failure leaves the record discoverable for a retry.
     *
     * @param  iterable<int, MessageAttachment>  $attachments
     */
    private function purgeRows(iterable $attachments): int
    {
        $disk = Storage::disk($this->disk());
        $removed = 0;

        foreach ($attachments as $attachment) {
            $disk->delete($attachment->storage_key);

            if ($attachment->thumbnail_key !== null) {
                $disk->delete($attachment->thumbnail_key);
            }

            $attachment->delete();
            $removed++;
        }

        return $removed;
    }

    public function disk(): string
    {
        return (string) config('chat.media.disk', 'media');
    }

    /**
     * @throws ValidationException
     */
    private function assertSafeBytes(MessageAttachment $attachment, string $contents): void
    {
        $declared = $attachment->mime_type;
        $sniffer = new \finfo(FILEINFO_MIME_TYPE);
        $detected = strtolower((string) $sniffer->buffer($contents));

        if (in_array(strtolower($detected), self::FORBIDDEN_DETECTED, true)) {
            throw ValidationException::withMessages([
                'upload' => __('The file content is not allowed.'),
            ]);
        }

        if ($attachment->isImage()) {
            $this->assertImageBytes($attachment, $contents);
        }

        if ($detected === '' || $detected === 'application/octet-stream') {
            // The sniffer has no opinion (common for tagged audio and some
            // archives). The declared type was already restricted to the
            // allowlist, so accept rather than reject valid files.
            return;
        }

        $aliases = self::MIME_ALIASES[$declared] ?? [];

        if ($detected !== $declared && ! in_array($detected, $aliases, true)) {
            throw ValidationException::withMessages([
                'upload' => __('The file content does not match its declared type.'),
            ]);
        }
    }

    /**
     * Images get an authoritative check: the bytes must decode as the
     * declared image format, which also yields the intrinsic dimensions.
     *
     * @throws ValidationException
     */
    private function assertImageBytes(MessageAttachment $attachment, string $contents): void
    {
        $info = @getimagesizefromstring($contents);

        if ($info === false) {
            throw ValidationException::withMessages([
                'upload' => __('The image file is malformed.'),
            ]);
        }

        [$width, $height] = $info;

        $expectedType = match ($attachment->mime_type) {
            'image/jpeg' => IMAGETYPE_JPEG,
            'image/png' => IMAGETYPE_PNG,
            'image/gif' => IMAGETYPE_GIF,
            'image/webp' => defined('IMAGETYPE_WEBP') ? IMAGETYPE_WEBP : null,
            default => null,
        };

        if ($expectedType !== null && $info[2] !== $expectedType) {
            throw ValidationException::withMessages([
                'upload' => __('The image content does not match its declared type.'),
            ]);
        }

        $attachment->width = (int) $width;
        $attachment->height = (int) $height;
    }

    private function extensionFor(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/webm' => 'weba',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/zip' => 'zip',
            'application/x-7z-compressed' => '7z',
            'application/gzip' => 'gz',
            default => 'bin',
        };
    }

    /**
     * Keep the client-provided filename display-only: no path separators, no
     * header-breaking characters, bounded length. It never reaches storage.
     */
    private function sanitizeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"]+/', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'file';
        }

        if (Str::length($name) > 255) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $base = Str::beforeLast($name, '.');

            $name = Str::limit($base, 255 - ($extension !== '' ? strlen($extension) + 1 : 0), '');

            if ($extension !== '') {
                $name .= '.'.$extension;
            }
        }

        return $name;
    }
}
