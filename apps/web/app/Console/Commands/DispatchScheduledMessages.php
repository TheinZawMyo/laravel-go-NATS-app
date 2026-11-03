<?php

namespace App\Console\Commands;

use App\Services\MessageService;
use App\Services\ScheduledMessageService;
use Illuminate\Console\Command;

class DispatchScheduledMessages extends Command
{
    protected $signature = 'messages:dispatch-scheduled {--limit=100}';

    protected $description = 'Deliver due scheduled messages';

    public function handle(ScheduledMessageService $scheduled, MessageService $messages): int
    {
        $processed = $scheduled->dispatchDue($messages, max(1, min((int) $this->option('limit'), 1000)));
        $this->info("Processed {$processed} due scheduled message(s).");

        return self::SUCCESS;
    }
}
