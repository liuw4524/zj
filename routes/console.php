<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('posts:freeze-expired')->hourly();
Schedule::command('clue-posts:delist-expired')->hourly();
Schedule::command('clue-posts:purge-stale')->daily();
Schedule::command('posts:remind-expiring')->hourly();
Schedule::command('escrows:finish-cooling')->hourly();
Schedule::command('escrows:remind')->hourly();
Schedule::command('pay:sync-pending')->everyMinute();
