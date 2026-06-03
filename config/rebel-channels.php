<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Provider fallback order
    |--------------------------------------------------------------------------
    | Verification provider keys to try, in order. The first one that supports the
    | channel and accepts the request wins. Leave empty to try every registered
    | provider that supports the channel (in registration order).
    */
    'providers' => [
        // 'twilio',
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-number rate limit (cooldown)
    |--------------------------------------------------------------------------
    | A fixed window per phone number + channel, to stop someone hammering a single
    | number with verification sends.
    */
    'rate_limit' => [
        'max_per_window' => (int) env('REBEL_CHANNELS_RL_MAX', 5),
        'window_seconds' => (int) env('REBEL_CHANNELS_RL_WINDOW', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anti toll-fraud / IRSF defences
    |--------------------------------------------------------------------------
    | - allowed_prefixes: when non-empty, ONLY numbers starting with one of these
    |   E.164 prefixes are allowed (a geo allowlist). Empty = allow everywhere.
    | - blocked_prefixes: numbers starting with one of these are always blocked.
    | - per_prefix: a velocity circuit breaker per coarse number prefix and channel.
    |   `length` is how many leading characters of the E.164 number form the bucket
    |   (e.g. 3 → "+39"). Set max_per_window to 0 to disable.
    */
    'fraud' => [
        'allowed_prefixes' => [
            // '+39', '+1',
        ],
        'blocked_prefixes' => [
            // '+225', '+88', '+963',
        ],
        'per_prefix' => [
            'length' => (int) env('REBEL_CHANNELS_PREFIX_LEN', 3),
            'max_per_window' => (int) env('REBEL_CHANNELS_PREFIX_MAX', 0),
            'window_seconds' => (int) env('REBEL_CHANNELS_PREFIX_WINDOW', 3600),
        ],
    ],

];
