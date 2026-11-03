<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('media:prune-uploads')->daily();
Schedule::command('media:purge-attachments')->daily();
Schedule::command('messages:dispatch-scheduled')->everyMinute()->withoutOverlapping();
