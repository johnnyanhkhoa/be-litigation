<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sync user references from CC DB daily at 2:00 AM
Schedule::command('sync:user-references')
    ->dailyAt('02:00')
    ->timezone('Asia/Yangon')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/user-sync.log'));
