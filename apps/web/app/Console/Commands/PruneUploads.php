<?php

namespace App\Console\Commands;

use App\Services\UploadService;
use Illuminate\Console\Command;

/**
 * Remove uploads that were presigned but never attached to a message, along
 * with their bytes. Without this, abandoned uploads would accumulate forever.
 */
class PruneUploads extends Command
{
    protected $signature = 'media:prune-uploads';

    protected $description = 'Delete abandoned attachment uploads and their stored files';

    public function handle(UploadService $uploads): int
    {
        $removed = $uploads->pruneAbandoned();

        $this->info("Removed {$removed} abandoned upload(s).");

        return self::SUCCESS;
    }
}
