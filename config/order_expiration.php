<?php

return [
    // Registering a command must never opt an existing database into writes.
    'enabled' => env('ORDER_EXPIRATION_ENABLED', false),
    'connection' => env('ORDER_EXPIRATION_CONNECTION'),
    'database' => env('ORDER_EXPIRATION_DATABASE'),
    // Multi-node production requires a reviewed shared Redis lock store.
    'lock_store' => env('ORDER_EXPIRATION_LOCK_STORE', 'file'),
];
