<?php

namespace App\Console\Commands;

use App\Services\UploadService;
use Illuminate\Console\Command;

/**
 * Remove attachments whose message was deleted, once the retention window has
 * passed. Deleting a message is a soft delete, so this is what actually
 * reclaims the bytes.
 */
class PurgeDeletedAttachments extends Command
{
    protected $signature = 'media:purge-attachments';

    protected $description = 'Delete stored files for attachments of deleted messages';

    public function handle(UploadService $uploads): int
    {
        $removed = $uploads->purgeDeletedMessageAttachments();

        $this->info("Removed {$removed} attachment(s) from deleted messages.");

        return self::SUCCESS;
    }
}
