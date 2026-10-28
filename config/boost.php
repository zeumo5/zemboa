<?php

return [
    'enabled' => env('APP_ENV') === 'local' && env('BOOST_ENABLED', true),
    'browser_logs_watcher' => false,
];
