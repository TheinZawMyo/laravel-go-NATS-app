<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Realtime Presence
    |--------------------------------------------------------------------------
    |
    | The Go gateway writes presence leases to Redis and refreshes them from the
    | websocket heartbeat. Reads therefore have to use the same Redis database
    | and the same key names, which is why presence has its own connection with
    | an empty key prefix: the Laravel cache prefix would otherwise hide the
    | gateway's keys.
    |
    */

    'presence' => [
        'enabled' => (bool) env('PRESENCE_ENABLED', true),

        'connection' => env('PRESENCE_REDIS_CONNECTION', 'presence'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Media and File Sharing
    |--------------------------------------------------------------------------
    |
    | Attachments live on a dedicated disk (local by default, S3/MinIO in
    | production) under random storage keys. Uploads are presigned: Laravel
    | validates the declared metadata first, then the signed upload endpoint
    | re-validates the actual bytes before they are stored.
    |
    */

    'media' => [
        'disk' => env('MEDIA_DISK', 'media'),

        'max_bytes' => (int) env('MEDIA_MAX_BYTES', 25 * 1024 * 1024),

        'upload_ttl_minutes' => (int) env('MEDIA_UPLOAD_TTL_MINUTES', 30),

        'pending_retention_hours' => (int) env('MEDIA_PENDING_RETENTION_HOURS', 24),

        'thumbnail_max_edge' => (int) env('MEDIA_THUMBNAIL_MAX_EDGE', 480),

        /*
         | Attachments of deleted messages are kept for a grace period so a
         | mistaken delete can still be investigated, then purged by the
         | `media:purge-attachments` command. Set to 0 to purge them on the
         | next run instead of waiting.
         */
        'deleted_message_grace_days' => (int) env('MEDIA_DELETED_MESSAGE_GRACE_DAYS', 7),

        /*
         | Malware scanning. Bytes are scanned before they are written to the
         | disk, so a positive result never leaves a stored file behind.
         |
         | `fail_closed` decides what happens when the scanner cannot answer:
         | true rejects the upload, false accepts it as unverified.
         */
        'scan' => [
            'enabled' => (bool) env('MEDIA_SCAN_ENABLED', false),

            'host' => env('MEDIA_SCAN_HOST', '127.0.0.1'),

            'port' => (int) env('MEDIA_SCAN_PORT', 3310),

            'timeout' => (float) env('MEDIA_SCAN_TIMEOUT', 5),

            'fail_closed' => (bool) env('MEDIA_SCAN_FAIL_CLOSED', false),
        ],

        /*
         | Video stills and audio/video duration come from ffmpeg/ffprobe.
         | When the binaries are missing the pipeline stores the file without
         | those extras instead of failing the upload.
         */
        'ffmpeg' => [
            'enabled' => (bool) env('MEDIA_FFMPEG_ENABLED', true),

            'ffmpeg' => env('MEDIA_FFMPEG_BINARY', 'ffmpeg'),

            'ffprobe' => env('MEDIA_FFPROBE_BINARY', 'ffprobe'),

            'timeout' => (int) env('MEDIA_FFMPEG_TIMEOUT', 20),
        ],

        /*
         | MIME types accepted for upload. The declared type must be in this
         | list AND the stored bytes must sniff to a compatible type. SVG and
         | HTML are deliberately absent: they can execute script when opened
         | inline.
         */
        'allowed_mime_types' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'MEDIA_ALLOWED_MIME_TYPES',
                'image/jpeg,image/png,image/gif,image/webp,'.
                'video/mp4,video/quicktime,video/webm,'.
                'audio/mpeg,audio/mp4,audio/wav,audio/ogg,audio/webm,'.
                'application/pdf,text/plain,text/csv,'.
                'application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,'.
                'application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,'.
                'application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation,'.
                'application/zip,application/x-7z-compressed,application/gzip',
            )),
        ))),
    ],

];
