<?php

declare(strict_types=1);

// No database-backed cache is available, including for login throttling.
return [
    'default' => 'array',
    'limiter' => 'demo_limiter',
    'prefix' => 'zemboa_demo_cache',
    'stores' => [
        'array' => ['driver' => 'array', 'serialize' => false],
        'demo_limiter' => [
            'driver' => 'file',
            'path' => storage_path('demo/limiter'),
            'lock_path' => storage_path('demo/limiter'),
        ],
    ],
];
