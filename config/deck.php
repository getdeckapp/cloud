<?php

/*
|--------------------------------------------------------------------------
| Deck Cloud (agent) configuration
|--------------------------------------------------------------------------
|
| The cloud agent's slice of the shared `deck.*` config namespace. Opt-in via
| DECK_API_KEY. Merged under the `deck` key so `config('deck.cloud.*')` and the
| DECK_* env vars are identical whether you install the slim agent (deck/cloud)
| or the full app (deck/deck). This subtree is owned solely by deck/cloud, so
| the shallow config merge never clobbers the core or dashboard slices.
|
*/

return [

    'cloud' => [
        'enabled' => env('DECK_CLOUD_ENABLED'),
        'url' => env('DECK_CLOUD_URL'),
        'api_key' => env('DECK_API_KEY'),
        'timeout_seconds' => (int) env('DECK_CLOUD_TIMEOUT', 5),
        'promo' => env('DECK_CLOUD_PROMO', true),
        'retry_attempts' => (int) env('DECK_CLOUD_RETRY_ATTEMPTS', 3),
        'log_failures' => (bool) env('DECK_CLOUD_LOG_FAILURES', true),
        'workers' => [
            'enabled' => (bool) env('DECK_CLOUD_WORKERS_ENABLED', true),
            'interval_seconds' => (int) env('DECK_CLOUD_WORKERS_INTERVAL', 30),
        ],
        'commands' => [
            'enabled' => (bool) env('DECK_CLOUD_COMMANDS_ENABLED', true),
        ],
        'events' => [
            'enabled' => (bool) env('DECK_CLOUD_EVENTS_ENABLED', true),
            'send_exception_trace' => (bool) env('DECK_CLOUD_SEND_EXCEPTION_TRACE', true),
            'batch_size' => (int) env('DECK_CLOUD_EVENTS_BATCH_SIZE', 25),
        ],
    ],

];
