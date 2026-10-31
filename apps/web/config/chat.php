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

];
