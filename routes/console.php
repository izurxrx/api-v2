<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ✅ Schedule: Mark No-Show bookings every hour
Schedule::command('bookings:mark-no-show')->hourly();

// ✅ Schedule: Detect overstaying guests every hour
Schedule::command('entries:mark-overstaying')->hourly();
