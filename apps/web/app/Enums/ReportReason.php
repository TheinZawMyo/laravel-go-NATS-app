<?php

namespace App\Enums;

enum ReportReason: string
{
    case Spam = 'spam';
    case Harassment = 'harassment';
    case Impersonation = 'impersonation';
    case Scam = 'scam';
    case InappropriateContent = 'inappropriate_content';
    case Other = 'other';
}
