import { ApiError, apiRequest } from './http';
import type { MessageAttachment } from '@/types';

/**
 * Keep in sync with `MEDIA_MAX_BYTES` in `config/chat.php`. The server is the
 * authority — this only gives the user fast feedback before a round trip.
 */
export const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;

type PresignedUpload = {
    data: MessageAttachment;
    upload: {
        method: string;
        url: string;
        expires_at: string | null;
    };
};

/**
 * Browsers occasionally report an empty `File.type` (notably for `.csv` and
 * some documents), and the presign endpoint requires a MIME type, so fall
 * back to a small extension map for the formats we accept.
 */
const EXTENSION_MIME_TYPES: Record<string, string> = {
    jpg: 'image/jpeg',
    jpeg: 'image/jpeg',
    png: 'image/png',
    gif: 'image/gif',
    webp: 'image/webp',
    mp4: 'video/mp4',
    mov: 'video/quicktime',
    webm: 'video/webm',
    mp3: 'audio/mpeg',
    m4a: 'audio/mp4',
    wav: 'audio/wav',
    ogg: 'audio/ogg',
    pdf: 'application/pdf',
    txt: 'text/plain',
    csv: 'text/csv',
    zip: 'application/zip',
    '7z': 'application/x-7z-compressed',
    gz: 'application/gzip',
    doc: 'application/msword',
    docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    xls: 'application/vnd.ms-excel',
    xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ppt: 'application/vnd.ms-powerpoint',
    pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
};

export function resolveMimeType(file: File): string {
    // Browsers report codec parameters for recorded media
    // (`audio/webm;codecs=opus`), but the presign endpoint validates against
    // the plain MIME type in `chat.media.allowed_mime_types`.
    const reported = file.type.split(';')[0]?.trim() ?? '';

    if (reported !== '') {
        return reported;
    }

    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

    return EXTENSION_MIME_TYPES[extension] ?? 'application/octet-stream';
}

/**
 * Reserve a storage slot and upload the bytes to the signed URL.
 * Returns the attachment id to reference from the message.
 */
export async function uploadFile(
    file: File,
    onProgress?: (percent: number) => void,
    signal?: AbortSignal,
): Promise<MessageAttachment> {
    if (file.size === 0) {
        throw new ApiError('This file is empty.', 422);
    }

    if (file.size > MAX_UPLOAD_BYTES) {
        throw new ApiError('This file exceeds the 25 MB limit.', 422);
    }

    const presigned = await apiRequest<PresignedUpload>(
        '/api/v1/uploads/presign',
        {
            method: 'POST',
            body: {
                original_name: file.name,
                mime_type: resolveMimeType(file),
                size_bytes: file.size,
            },
            signal,
        },
    );

    await putFile(presigned.upload.url, file, onProgress, signal);

    return presigned.data;
}

/**
 * Raw PUT with upload progress. `fetch` cannot report request progress, so
 * this is the one place in the app that uses XMLHttpRequest.
 */
function putFile(
    url: string,
    file: File,
    onProgress?: (percent: number) => void,
    signal?: AbortSignal,
): Promise<void> {
    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();

        const abort = (): void => {
            request.abort();
        };

        if (signal !== undefined) {
            if (signal.aborted) {
                reject(new ApiError('Upload cancelled.', 0));

                return;
            }

            signal.addEventListener('abort', abort, { once: true });
        }

        const cleanup = (): void => {
            signal?.removeEventListener('abort', abort);
        };

        request.open('PUT', url);

        request.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable && onProgress !== undefined) {
                onProgress(
                    Math.min(
                        100,
                        Math.round((event.loaded / event.total) * 100),
                    ),
                );
            }
        });

        request.addEventListener('load', () => {
            cleanup();

            if (request.status >= 200 && request.status < 300) {
                onProgress?.(100);
                resolve();

                return;
            }

            reject(uploadError(request));
        });

        request.addEventListener('error', () => {
            cleanup();
            reject(
                new ApiError(
                    'The upload failed. Check your connection and try again.',
                    0,
                ),
            );
        });

        request.addEventListener('abort', () => {
            cleanup();
            reject(new ApiError('Upload cancelled.', 0));
        });

        request.send(file);
    });
}

function uploadError(request: XMLHttpRequest): ApiError {
    let message = `Upload failed with status ${request.status}.`;
    let errors: Record<string, string[]> = {};

    try {
        const payload = JSON.parse(request.responseText) as {
            message?: string;
            errors?: Record<string, string[]>;
        };

        const firstError = Object.values(payload.errors ?? {})[0]?.[0];

        message = firstError ?? payload.message ?? message;
        errors = payload.errors ?? {};
    } catch {
        // Non-JSON error body (proxy page, HTML error): keep the status message.
    }

    return new ApiError(message, request.status, errors);
}
