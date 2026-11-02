<?php

namespace App\Jobs;

use App\Models\MessageAttachment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Best-effort media processing for a freshly uploaded attachment: an image
 * preview, or a video still plus the playtime shown in the bubble.
 *
 * Everything here is optional decoration. A missing GD/ffmpeg, an unreadable
 * file, or a corrupt stream leaves the original attachment untouched, because
 * the file itself is already usable without the extras.
 */
class ProcessAttachment implements ShouldQueue
{
    use Queueable;

    /**
     * A short-lived infrastructure hiccup should not lose the preview, but a
     * broken file must not be retried forever either.
     */
    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly string $attachmentId,
    ) {}

    public function handle(): void
    {
        $attachment = MessageAttachment::query()->find($this->attachmentId);

        if ($attachment === null
            || $attachment->uploaded_at === null
            || ! $attachment->isSendable()) {
            return;
        }

        $disk = Storage::disk((string) config('chat.media.disk', 'media'));

        if (! $disk->exists($attachment->storage_key)) {
            return;
        }

        if ($attachment->isImage()) {
            if ($attachment->thumbnail_key === null) {
                $this->generateImageThumbnail($attachment, $disk);
            }

            return;
        }

        if ($attachment->isVideo() && $attachment->thumbnail_key === null) {
            $this->generateVideoThumbnail($attachment, $disk);
        }

        if ($attachment->duration_ms === null
            && ($attachment->isVideo() || $attachment->isAudio())) {
            $this->probeDuration($attachment, $disk);
        }
    }

    private function generateImageThumbnail(MessageAttachment $attachment, Filesystem $disk): void
    {
        $contents = $disk->get($attachment->storage_key);
        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            return;
        }

        $maxEdge = max(16, (int) config('chat.media.thumbnail_max_edge', 480));
        $width = imagesx($source);
        $height = imagesy($source);

        $scale = min(1.0, $maxEdge / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($thumbnail === false) {
            imagedestroy($source);

            return;
        }

        // Flatten transparency onto white so JPEG previews do not go black.
        $background = imagecolorallocate($thumbnail, 255, 255, 255);

        if ($background === false) {
            imagedestroy($source);
            imagedestroy($thumbnail);

            return;
        }

        imagefilledrectangle($thumbnail, 0, 0, $targetWidth, $targetHeight, $background);
        imagecopyresampled(
            $thumbnail,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height,
        );

        ob_start();
        imagejpeg($thumbnail, null, 82);
        $encoded = ob_get_clean();

        imagedestroy($source);
        imagedestroy($thumbnail);

        if (! is_string($encoded) || $encoded === '') {
            return;
        }

        $this->storeThumbnail($attachment, $disk, $encoded);
    }

    private function generateVideoThumbnail(MessageAttachment $attachment, Filesystem $disk): void
    {
        $binary = $this->locate((string) config('chat.media.ffmpeg.ffmpeg', 'ffmpeg'));

        if ($binary === null) {
            return;
        }

        $source = $this->temporaryCopy($attachment, $disk);

        if ($source === null) {
            return;
        }

        $frame = $source.'.jpg';

        try {
            $maxEdge = max(16, (int) config('chat.media.thumbnail_max_edge', 480));

            $process = new Process([
                $binary,
                '-y',
                '-loglevel', 'error',
                // A still from the first second avoids the black frame that
                // many encoders start with.
                '-ss', '1',
                '-i', $source,
                '-frames:v', '1',
                '-vf', sprintf('scale=%d:-2:force_original_aspect_ratio=decrease', $maxEdge),
                '-f', 'image2',
                $frame,
            ]);

            $process->setTimeout((float) config('chat.media.ffmpeg.timeout', 20));
            $process->run();

            if (! $process->isSuccessful() || ! is_file($frame)) {
                return;
            }

            $contents = file_get_contents($frame);

            if ($contents === false || $contents === '') {
                return;
            }

            $this->storeThumbnail($attachment, $disk, $contents);
        } finally {
            @unlink($source);
            @unlink($frame);
        }
    }

    private function probeDuration(MessageAttachment $attachment, Filesystem $disk): void
    {
        $binary = $this->locate((string) config('chat.media.ffmpeg.ffprobe', 'ffprobe'));

        if ($binary === null) {
            return;
        }

        $source = $this->temporaryCopy($attachment, $disk);

        if ($source === null) {
            return;
        }

        try {
            $process = new Process([
                $binary,
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $source,
            ]);

            $process->setTimeout((float) config('chat.media.ffmpeg.timeout', 20));
            $process->run();

            if (! $process->isSuccessful()) {
                return;
            }

            $duration = trim($process->getOutput());

            if (! is_numeric($duration) || (float) $duration <= 0) {
                return;
            }

            $attachment->forceFill([
                'duration_ms' => (int) round((float) $duration * 1000),
            ])->save();
        } finally {
            @unlink($source);
        }
    }

    private function storeThumbnail(MessageAttachment $attachment, Filesystem $disk, string $contents): void
    {
        $base = preg_replace('/\.[^.]+$/', '', $attachment->storage_key);

        if ($base === null || $base === '') {
            return;
        }

        $thumbnailKey = $base.'_thumb.jpg';

        if (! $disk->put($thumbnailKey, $contents)) {
            return;
        }

        $attachment->forceFill(['thumbnail_key' => $thumbnailKey])->save();
    }

    /**
     * ffmpeg and ffprobe need a local path, which a remote disk does not have,
     * so the file is copied into the system temp directory for the duration of
     * the process. The copy is deleted by the caller.
     */
    private function temporaryCopy(MessageAttachment $attachment, Filesystem $disk): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'chat-media-');

        if ($path === false) {
            return null;
        }

        $stream = $disk->readStream($attachment->storage_key);

        if ($stream === null) {
            @unlink($path);

            return null;
        }

        $target = fopen($path, 'wb');

        if ($target === false) {
            fclose($stream);
            @unlink($path);

            return null;
        }

        stream_copy_to_stream($stream, $target);
        fclose($stream);
        fclose($target);

        return $path;
    }

    private function locate(string $binary): ?string
    {
        if (! (bool) config('chat.media.ffmpeg.enabled', true) || $binary === '') {
            return null;
        }

        return (new ExecutableFinder)->find($binary);
    }

    /**
     * A corrupt file is not worth retrying forever; the original attachment is
     * still served even when the extras cannot be built.
     */
    public function failed(?Throwable $e): void
    {
        // Intentionally empty: failures are visible in the failed_jobs table.
    }
}
