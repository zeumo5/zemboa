<?php

declare(strict_types=1);

// Loaded only by the demo bootstrap; never evaluates MySQL configuration or credentials.
return [
    'default' => 'sqlite_demo',
    'connections' => [
        'sqlite_demo' => [
            'driver' => 'sqlite',
            'url' => null,
            'database' => database_path('zemboa_demo.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
            'transaction_mode' => 'IMMEDIATE',
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
    'redis' => [],
];
