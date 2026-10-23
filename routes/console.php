<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// No event at all unless separately authorized configuration enables expiration.
// The command independently checks its target and owns the batch lock.
if (config('order_expiration.enabled') === true && !app()->environment('demo')) {
    \Illuminate\Support\Facades\Schedule::command('orders:expire-unpaid')
        ->everyMinute()->name('orders-expire-unpaid');
}
