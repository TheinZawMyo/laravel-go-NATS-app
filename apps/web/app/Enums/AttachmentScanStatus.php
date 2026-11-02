<?php

namespace App\Enums;

/**
 * Malware scan state of a stored attachment.
 *
 * `Skipped` means "not verified" (scanning disabled, or the scanner could not
 * be reached while the deployment is configured to fail open), so it is a
 * sendable state like `Clean`. `Infected` and `Failed` are terminal: the bytes
 * are never stored for those rows.
 */
enum AttachmentScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Skipped = 'skipped';
    case Infected = 'infected';
    case Failed = 'failed';

    /**
     * Only verified or explicitly unverified-but-accepted rows may be sent.
     */
    public function isSendable(): bool
    {
        return $this === self::Clean || $this === self::Skipped;
    }
}
