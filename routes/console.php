<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tencia sync
|--------------------------------------------------------------------------
|
| Overnight so a planner arriving in the morning sees yesterday's close. Stock
| moves hourly through the day. The order book is read from Tencia when a plan
| is built and is not copied locally. The product master, bills of material and
| history change rarely and only need the nightly run.
|
| Each sync is idempotent, so an overlapping or repeated run is harmless.
| withoutOverlapping guards the long history loads regardless.
|
*/

Schedule::command('sync:products')->dailyAt('04:00')->withoutOverlapping();
Schedule::command('sync:bom')->dailyAt('04:15')->withoutOverlapping();
Schedule::command('sync:sales-history')->dailyAt('04:30')->withoutOverlapping();
Schedule::command('sync:production-history')->dailyAt('04:45')->withoutOverlapping();

Schedule::command('sync:stock')->hourly()->withoutOverlapping();
