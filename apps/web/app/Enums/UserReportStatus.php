<?php

namespace App\Enums;

enum UserReportStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
